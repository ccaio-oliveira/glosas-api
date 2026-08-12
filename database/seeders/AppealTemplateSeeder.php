<?php

namespace Database\Seeders;

use App\Models\AppealTemplate;
use Illuminate\Database\Seeder;

class AppealTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $t) {
            AppealTemplate::updateOrCreate(
                [
                    'clinic_id' => null,
                    'scope' => $t['scope'],
                    'denial_reason_code' => $t['denial_reason_code'] ?? null,
                    'tiss_group' => $t['tiss_group'] ?? null,
                    'category' => $t['category'] ?? null,
                ],
                [
                    'name' => $t['name'],
                    'body' => $this->letter($t['argument']),
                    'legal_basis' => null, // PREENCHER: fundamentação validada
                    'required_attachments' => $t['attachments'],
                    'requires_clinical_input' => $t['clinical'] ?? false,
                    'is_active' => true,
                ],
            );
        }
    }

    /** Esqueleto comum da carta; cada template injeta só o argumento. */
    private function letter(string $argument): string
    {
        return <<<TXT
        Ao Departamento de Auditoria — {{payer_name}}

        RECURSO DE GLOSA — Guia nº {{claim_number}}

        Paciente: {{patient_name}}
        Procedimento: {{procedure_description}} (código {{procedure_code}})
        Valor apresentado: {{billed_amount}}
        Valor glosado: {{denied_amount}}
        Motivo informado: {{reason_code}} — {{reason_description}}
        Data da glosa: {{identified_at}}

        Prezados,

        Vimos, por meio deste, contestar formalmente a glosa aplicada à guia acima referenciada, no valor de {{denied_amount}}.

        {$argument}

        {{clinical_section}}

        {{legal_basis}}

        Documentos anexos:
        {{attachments}}

        Diante do exposto, requeremos a revisão da glosa e o pagamento integral do valor de {{denied_amount}}.

        Respeitosamente,

        {{clinic_name}}
        CNPJ {{clinic_cnpj}} — {{clinic_cro}}
        {{today}}
        TXT;
    }

    private function templates(): array
    {
        return [
            // ── Nível código ────────────────────────────────────────────────
            [
                'scope' => 'code', 'denial_reason_code' => '1402',
                'name' => 'Procedimento não autorizado',
                'attachments' => ['Guia TISS original', 'Comprovante de autorização prévia', 'Prontuário odontológico'],
                'argument' => 'O procedimento foi realizado mediante autorização prévia regularmente obtida junto a esta operadora, cujo comprovante segue anexo. A ausência do registro da autorização na análise não descaracteriza a validade do atendimento prestado nem justifica o não pagamento do procedimento efetivamente executado.',
            ],
            [
                'scope' => 'code', 'denial_reason_code' => '1702',
                'name' => 'Cobrança em duplicidade',
                'attachments' => ['Guia TISS original', 'Prontuário com datas de atendimento'],
                'argument' => 'Não há duplicidade na cobrança. O procedimento em questão foi executado em atendimento distinto, em data e contexto clínico próprios, conforme demonstra o prontuário anexo. Tratando-se de eventos independentes, cada um comporta cobrança autônoma.',
            ],
            [
                'scope' => 'code', 'denial_reason_code' => '3007',
                'name' => 'Procedimentos odontológicos duplicados',
                'clinical' => true,
                'attachments' => ['Guia TISS original', 'Prontuário odontológico com evolução', 'Documentação radiográfica'],
                'argument' => 'Os procedimentos apontados como duplicados referem-se a elementos dentários e/ou faces distintas, não configurando repetição do mesmo evento. A documentação anexa individualiza cada intervenção realizada.',
            ],
            [
                'scope' => 'code', 'denial_reason_code' => '3009',
                'name' => 'Procedimento odontológico não executado',
                'clinical' => true,
                'attachments' => ['Prontuário odontológico com evolução', 'Documentação radiográfica', 'Comprovante de comparecimento do beneficiário'],
                'argument' => 'O procedimento foi efetivamente executado, conforme comprova a documentação clínica anexa, que registra a data de realização, o profissional responsável e a evolução do tratamento.',
            ],
            [
                'scope' => 'code', 'denial_reason_code' => '3052',
                'name' => 'Documentação incompleta, incorreta ou ausente',
                'attachments' => ['Documentação complementar solicitada', 'Guia TISS original', 'Prontuário odontológico'],
                'argument' => 'Encaminhamos, em anexo, a documentação complementar apontada como ausente, sanando integralmente a pendência que motivou a glosa. Considerando que a falha era de natureza documental e não afeta a validade do procedimento realizado, requeremos o processamento regular da guia.',
            ],
            [
                'scope' => 'code', 'denial_reason_code' => '3081',
                'name' => 'Radiografia inicial não enviada',
                'attachments' => ['Radiografia inicial', 'Prontuário odontológico'],
                'argument' => 'Segue anexa a radiografia inicial do elemento tratado, documento que fundamenta a indicação clínica do procedimento executado e supre a pendência apontada.',
            ],
            [
                'scope' => 'code', 'denial_reason_code' => '3067',
                'name' => 'Radiografia final não enviada',
                'attachments' => ['Radiografia final', 'Prontuário odontológico'],
                'argument' => 'Segue anexa a radiografia final do elemento tratado, comprovando a conclusão e a adequação técnica do procedimento executado.',
            ],

            // ── Nível grupo ─────────────────────────────────────────────────
            [
                'scope' => 'group', 'tiss_group' => 'autorizacao',
                'name' => 'Genérico — Autorização',
                'attachments' => ['Guia TISS original', 'Comprovante de autorização', 'Prontuário odontológico'],
                'argument' => 'A glosa fundamenta-se em questão de autorização. Conforme documentação anexa, o atendimento observou os trâmites de autorização aplicáveis, e eventual divergência de registro é de ordem administrativa, não afetando a validade do procedimento efetivamente prestado ao beneficiário.',
            ],
            [
                'scope' => 'group', 'tiss_group' => 'guia',
                'name' => 'Genérico — Guia',
                'attachments' => ['Guia TISS corrigida', 'Guia TISS original', 'Prontuário odontológico'],
                'argument' => 'A glosa decorre de inconsistência no preenchimento da guia. Encaminhamos a guia devidamente regularizada em anexo. Tratando-se de falha formal de preenchimento, e estando comprovada a efetiva prestação do serviço, não há fundamento para o não pagamento do procedimento realizado.',
            ],
            [
                'scope' => 'group', 'tiss_group' => 'odontologia',
                'name' => 'Genérico — Odontologia',
                'clinical' => true,
                'attachments' => ['Prontuário odontológico com evolução', 'Documentação radiográfica', 'Guia TISS original'],
                'argument' => 'A glosa fundamenta-se em análise técnica do procedimento odontológico executado. Apresentamos a seguir a justificativa clínica que ampara a conduta adotada, acompanhada da documentação comprobatória anexa.',
            ],
            [
                'scope' => 'group', 'tiss_group' => 'cobranca',
                'name' => 'Genérico — Cobrança e valores',
                'attachments' => ['Guia TISS original', 'Tabela contratada vigente', 'Contrato firmado com a operadora'],
                'argument' => 'A glosa refere-se a divergência de valores. O valor apresentado observa a tabela contratada vigente entre as partes, conforme documentação anexa. Requeremos a revisão do cálculo aplicado e o pagamento conforme os termos contratuais em vigor.',
            ],

            // ── Nível categoria (rede de segurança) ─────────────────────────
            [
                'scope' => 'category', 'category' => 'administrative',
                'name' => 'Genérico — Administrativa',
                'attachments' => ['Guia TISS original', 'Prontuário odontológico', 'Documentação complementar'],
                'argument' => 'A glosa aplicada é de natureza administrativa e não questiona a efetiva realização do procedimento. Estando comprovada a prestação do serviço ao beneficiário, conforme documentação anexa, requeremos a revisão da glosa e o processamento regular da guia.',
            ],
            [
                'scope' => 'category', 'category' => 'technical',
                'name' => 'Genérico — Técnica',
                'clinical' => true,
                'attachments' => ['Prontuário odontológico com evolução', 'Documentação radiográfica', 'Guia TISS original'],
                'argument' => 'A glosa aplicada é de natureza técnica. O procedimento foi indicado e executado com base no quadro clínico do beneficiário, competindo ao cirurgião-dentista responsável a definição do plano de tratamento adequado. Apresentamos a seguir a fundamentação clínica do caso.',
            ],
            [
                'scope' => 'category', 'category' => 'linear',
                'name' => 'Genérico — Valores',
                'attachments' => ['Guia TISS original', 'Tabela contratada vigente', 'Contrato firmado com a operadora'],
                'argument' => 'A glosa refere-se a divergência de valor ou quantidade. Os valores apresentados observam os termos contratados entre as partes, conforme documentação anexa. Requeremos a revisão do processamento e o pagamento conforme o contrato vigente.',
            ],
            [
                'scope' => 'category', 'category' => 'unknown',
                'name' => 'Genérico — Motivo não identificado',
                'attachments' => ['Guia TISS original', 'Demonstrativo de análise de conta', 'Prontuário odontológico'],
                'argument' => 'O motivo informado para a glosa não permite identificação objetiva da razão da recusa: o código apresentado não corresponde a nenhum termo da Tabela 38 do padrão TISS (Terminologia de mensagens: glosas, negativas e outras), ou não foi informado. A ausência de motivação clara e padronizada impede o exercício pleno do direito de defesa, uma vez que não é possível endereçar especificamente o fundamento da recusa. Requeremos, portanto, que esta operadora (a) identifique o motivo da glosa por meio do código correspondente da Tabela 38, com a devida descrição, ou (b) não subsistindo motivação válida, proceda ao pagamento integral do valor glosado. Registramos que o procedimento foi efetivamente executado e devidamente documentado, conforme comprova a documentação anexa.',
            ],
        ];
    }
}
