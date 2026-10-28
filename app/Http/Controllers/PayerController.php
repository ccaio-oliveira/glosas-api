<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClinicPayer;
use App\Models\Payer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PayerController extends Controller
{
    private const RULES = [
        'name' => ['required', 'string', 'max:255'],
        'ans_registry_code' => ['nullable', 'string', 'max:255'],
        'integration_type' => ['required', 'in:manual,tiss_webservice'],
        'appeal_deadline_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        // Caractere de controle não tem uso legítimo num campo de uma linha e
        // atrapalha o PDF e o log de auditoria. Os invisíveis e de direção do
        // Unicode (U+200B–200F, 2028–202E, 2066–2069) mascaram o que se lê.
        // A obrigatoriedade fica em rules().
        'appeal_deadline_source' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u'],
    ];

    private const MESSAGES = [
        'appeal_deadline_days.integer' => 'O prazo de recurso deve ser um número inteiro de dias.',
        'appeal_deadline_days.min' => 'O prazo de recurso deve ser de pelo menos 1 dia.',
        'appeal_deadline_days.max' => 'O prazo de recurso deve ser de no máximo 365 dias.',
        'appeal_deadline_source.required' => 'Informe a fonte do prazo (ex.: contrato e cláusula).',
        'appeal_deadline_source.string' => 'A fonte do prazo deve ser um texto.',
        'appeal_deadline_source.max' => 'A fonte do prazo deve ter no máximo 255 caracteres.',
        'appeal_deadline_source.not_regex' => 'A fonte do prazo contém caracteres inválidos.',
    ];

    public function index(Request $request)
    {
        $links = ClinicPayer::with('payer')->get();
        $names = $this->updaterNames($links);

        return $links->map(fn (ClinicPayer $link) => $this->present($link, $names));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request), self::MESSAGES);

        $link = DB::transaction(function () use ($request, $data) {
            $payer = Payer::whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->first();

            if (! $payer) {
                $payer = Payer::create([
                    'name' => $data['name'],
                    'ans_registry_code' => $data['ans_registry_code'] ?? null,
                ]);
            } elseif (! $payer->ans_registry_code && ! empty($data['ans_registry_code'])) {
                $payer->update(['ans_registry_code' => $data['ans_registry_code']]);
            }

            $link = ClinicPayer::firstOrNew(
                ['clinic_id' => $request->user()->clinic_id, 'payer_id' => $payer->id],
            );
            $link->integration_type = $data['integration_type'];

            // "Novo convênio" com o nome de um já vinculado cai no mesmo vínculo.
            // Prazo vazio ali é campo não preenchido, não pedido para apagar o
            // prazo que já existe — isso se faz pela edição.
            if ($link->exists && ! filled($data['appeal_deadline_days'] ?? null)) {
                unset($data['appeal_deadline_days'], $data['appeal_deadline_source']);
            }

            $changes = $this->applyDeadline($link, $data, $request->user()->id);
            $link->save();

            $this->auditDeadline($link->load('payer'), $changes);

            return $link;
        });

        return $this->present($link, $this->updaterNames(collect([$link])));
    }

    public function update(Request $request, ClinicPayer $clinicPayer)
    {
        $data = $request->validate($this->rules($request, $clinicPayer), self::MESSAGES);

        DB::transaction(function () use ($request, $data, $clinicPayer) {
            $payer = $clinicPayer->payer;
            $sharedDataChanged = $data['name'] !== $payer->name || ($data['ans_registry_code'] ?? null) !== $payer->ans_registry_code;

            if ($sharedDataChanged) {
                $usedByOtherClinics = ClinicPayer::where('payer_id', $payer->id)
                    ->where('id', '!=', $clinicPayer->id)
                    ->exists();

                if ($usedByOtherClinics) {
                    $payer = Payer::create([
                        'name' => $data['name'],
                        'ans_registry_code' => $data['ans_registry_code'] ?? null,
                    ]);

                    $clinicPayer->payer_id = $payer->id;
                } else {
                    $payer->update([
                        'name' => $data['name'],
                        'ans_registry_code' => $data['ans_registry_code'] ?? null,
                    ]);
                }
            }

            $clinicPayer->integration_type = $data['integration_type'];
            $changes = $this->applyDeadline($clinicPayer, $data, $request->user()->id);
            $clinicPayer->save();

            $this->auditDeadline($clinicPayer->load('payer'), $changes);
        });

        return $this->present($clinicPayer, $this->updaterNames(collect([$clinicPayer])));
    }

    /**
     * Desvincular apaga junto o prazo de recurso: as glosas do convênio passam a
     * "sem prazo". Como isso mexe no que a clínica ainda pode cobrar, o prazo
     * que existia fica registrado antes de sumir.
     */
    public function destroy(ClinicPayer $clinicPayer)
    {
        DB::transaction(function () use ($clinicPayer) {
            if ($days = $clinicPayer->appealDeadlineDays()) {
                AuditLog::record(
                    action: 'payer.unlinked',
                    auditable: $clinicPayer,
                    summary: "Convênio {$clinicPayer->payer->name} removido com prazo de recurso de {$days} dias",
                    changes: [
                        'appeal_deadline_days' => ['from' => $days, 'to' => null],
                        'appeal_deadline_source' => ['from' => $clinicPayer->appealDeadlineSource(), 'to' => null],
                    ],
                );
            }

            $clinicPayer->delete();
        });

        return response()->noContent();
    }

    /**
     * Fonte é obrigatória junto do prazo: prazo sem origem não se sustenta
     * quando o convênio contesta. A exceção é o prazo legado, cadastrado antes
     * de a fonte existir: reenviado igual, sem fonte, não bloqueia a edição do
     * nome ou do registro ANS. Mudou o prazo, a fonte passa a ser exigida.
     */
    private function rules(Request $request, ?ClinicPayer $link = null): array
    {
        $days = $request->input('appeal_deadline_days');
        $keepsLegacy = $link
            && $link->appealDeadlineDays() !== null
            && $link->appealDeadlineSource() === null
            && is_numeric($days) && (int) $days === $link->appealDeadlineDays();

        $rules = self::RULES;
        $rules['appeal_deadline_source'][] = Rule::requiredIf(filled($days) && ! $keepsLegacy);

        return $rules;
    }

    /**
     * Quem mexeu no prazo só aparece para quem opera: o viewer vê o prazo, mas
     * não precisa do nome de quem o cadastrou. O id fica no config; o nome é
     * resolvido aqui, numa consulta só, e some se o usuário foi excluído.
     *
     * @param  Collection<int, ClinicPayer>  $links
     * @return array<int, string> user_id => nome
     */
    private function updaterNames(Collection $links): array
    {
        if (! Gate::allows('operate')) {
            return [];
        }

        $ids = $links->map(fn (ClinicPayer $link) => $link->config['appeal_deadline_updated_by_user_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::whereIn('id', $ids)
            ->where('clinic_id', auth()->user()->clinic_id)
            ->pluck('name', 'id')
            ->all();
    }

    /** @param  array<int, string>  $names */
    private function present(ClinicPayer $link, array $names = []): array
    {
        $updatedBy = $link->config['appeal_deadline_updated_by_user_id'] ?? null;

        return [
            'id' => $link->id,
            'payer_id' => $link->payer_id,
            'name' => $link->payer->name,
            'ans_registry_code' => $link->payer->ans_registry_code,
            'integration_type' => $link->integration_type,
            'appeal_deadline_days' => $link->appealDeadlineDays(),
            'appeal_deadline_source' => $link->appealDeadlineSource(),
            'appeal_deadline_updated_at' => $link->config['appeal_deadline_updated_at'] ?? null,
            'appeal_deadline_updated_by' => $updatedBy ? ($names[$updatedBy] ?? null) : null,
        ];
    }

    /**
     * Regras por convênio ficam em `clinic_payers.config` — é do vínculo da
     * clínica com o convênio, não do convênio global: cada contrato é um.
     * Mescla em vez de sobrescrever, para regras futuras não se apagarem, e só
     * grava chaves conhecidas: os metadados `appeal_deadline_updated_*` vêm do
     * servidor, nunca do corpo da requisição.
     *
     * Sem a chave `appeal_deadline_days` no corpo, o prazo fica como está.
     * Com ela vazia, prazo e fonte são limpos juntos — fonte sem prazo não
     * descreve nada. Data e autor só mudam quando prazo ou fonte mudam de fato,
     * senão salvar o formulário sem mexer apagaria quem configurou.
     *
     * @return array<string, array{from: mixed, to: mixed}>|null o que mudou, para a auditoria
     */
    private function applyDeadline(ClinicPayer $link, array $data, int $userId): ?array
    {
        if (! array_key_exists('appeal_deadline_days', $data)) {
            return null;
        }

        $fromDays = $link->appealDeadlineDays();
        $fromSource = $link->appealDeadlineSource();
        $toDays = $data['appeal_deadline_days'] ? (int) $data['appeal_deadline_days'] : null;
        $toSource = $toDays ? ($data['appeal_deadline_source'] ?? null) : null;

        if ($fromDays === $toDays && $fromSource === $toSource) {
            return null;
        }

        $link->config = array_merge($link->config ?? [], [
            'appeal_deadline_days' => $toDays,
            'appeal_deadline_source' => $toSource,
            // No fuso de negócio: o offset preserva o dia que a clínica viveu.
            'appeal_deadline_updated_at' => now(config('app.business_timezone'))->toIso8601String(),
            'appeal_deadline_updated_by_user_id' => $userId,
        ]);

        return [
            'appeal_deadline_days' => ['from' => $fromDays, 'to' => $toDays],
            'appeal_deadline_source' => ['from' => $fromSource, 'to' => $toSource],
        ];
    }

    /**
     * Prazo de recurso decide se a clínica ainda pode cobrar o valor glosado —
     * mexe em dinheiro, então fica registrado. Só prazo e fonte vão para o log,
     * não o config inteiro, que pode carregar regras sem relação com isto.
     */
    private function auditDeadline(ClinicPayer $link, ?array $changes): void
    {
        if (! $changes) {
            return;
        }

        $from = $changes['appeal_deadline_days']['from'];
        $to = $changes['appeal_deadline_days']['to'];
        $label = fn (?int $days) => match (true) {
            $days === null => 'não configurado',
            $days === 1 => '1 dia',
            default => "{$days} dias",
        };

        $summary = match (true) {
            $from === $to => "Fonte do prazo de recurso de {$link->payer->name} alterada",
            $from !== null && $to !== null => "Prazo de recurso de {$link->payer->name}: {$from} → {$label($to)}",
            default => "Prazo de recurso de {$link->payer->name}: {$label($from)} → {$label($to)}",
        };

        AuditLog::record(
            action: 'payer.deadline_updated',
            auditable: $link,
            summary: $summary,
            changes: $changes,
        );
    }
}
