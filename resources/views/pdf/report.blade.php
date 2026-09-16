@php
    $fmt = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $totals = $report['totals'];
    $maxMonth = collect($report['by_month'])->max('denied_amount') ?: 1;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 2cm 1.8cm; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5pt;
            line-height: 1.5;
            color: #1a1a1a;
        }
        .letterhead {
            border-bottom: 2px solid #1e3a5f;
            padding-bottom: 10px;
            margin-bottom: 8px;
        }
        .clinic-name { font-size: 14pt; font-weight: bold; color: #1e3a5f; }
        .clinic-meta { font-size: 8.5pt; color: #555; margin-top: 3px; }
        .title { font-size: 12pt; font-weight: bold; margin: 18px 0 2px; }
        .period { font-size: 8.5pt; color: #666; margin-bottom: 16px; }

        .cards { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-bottom: 20px; }
        .cards td {
            border: 1px solid #dfe3e8;
            border-radius: 6px;
            padding: 10px 12px;
            width: 33%;
        }
        .card-label { font-size: 8pt; color: #666; }
        .card-value { font-size: 15pt; font-weight: bold; margin-top: 3px; }

        h2 {
            font-size: 10pt;
            color: #1e3a5f;
            margin: 20px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #dfe3e8;
        }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th {
            text-align: left;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #777;
            padding: 4px 6px;
            border-bottom: 1px solid #dfe3e8;
        }
        table.data td { padding: 5px 6px; border-bottom: 1px solid #f0f2f4; }
        .num { text-align: right; white-space: nowrap; }
        .bar-track { background: #eef0f3; height: 6px; width: 100%; }
        .bar-fill { height: 6px; background: #1e3a5f; }
        .muted { color: #777; }
        .footer {
            position: fixed;
            bottom: -1cm;
            left: 0; right: 0;
            font-size: 7.5pt;
            color: #888;
            border-top: 1px solid #ddd;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    <div class="letterhead">
        <div class="clinic-name">{{ $clinic->name }}</div>
        <div class="clinic-meta">
            @if($clinic->cnpj) CNPJ {{ $clinic->cnpj }} @endif
            @if($clinic->cro) &nbsp;·&nbsp; {{ $clinic->cro }} @endif
        </div>
    </div>

    <div class="title">Relatório de Recuperação de Glosas</div>
    <div class="period">
        @if($report['period']['from'] || $report['period']['to'])
            Período de
            {{ $report['period']['from'] ? \Carbon\Carbon::parse($report['period']['from'])->format('d/m/Y') : 'início' }}
            a
            {{ $report['period']['to'] ? \Carbon\Carbon::parse($report['period']['to'])->format('d/m/Y') : 'hoje' }}
        @else
            Todo o período
        @endif
        &nbsp;·&nbsp; {{ $totals['denial_count'] }} glosa(s)
    </div>

    <table class="cards">
        <tr>
            <td>
                <div class="card-label">Total glosado</div>
                <div class="card-value" style="color:#c0392b">{{ $fmt($totals['denied_amount']) }}</div>
            </td>
            <td>
                <div class="card-label">Total recuperado</div>
                <div class="card-value" style="color:#1e8449">{{ $fmt($totals['recovered_amount']) }}</div>
            </td>
            <td>
                <div class="card-label">Taxa de recuperação</div>
                <div class="card-value" style="color:#1e3a5f">
                    {{ $totals['recovery_rate'] !== null ? $totals['recovery_rate'].'%' : '—' }}
                </div>
            </td>
        </tr>
    </table>

    <h2>Distribuição por status</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Status</th>
                <th class="num">Glosas</th>
                <th style="width:35%">&nbsp;</th>
                <th class="num">Valor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['by_status'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['count'] }}</td>
                    <td>
                        <div class="bar-track"><div class="bar-fill" style="width:{{ $row['share'] }}%"></div></div>
                    </td>
                    <td class="num">{{ $fmt($row['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Desempenho por convênio</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Convênio</th>
                <th class="num">Glosas</th>
                <th class="num">Glosado</th>
                <th class="num">Recuperado</th>
                <th class="num">Taxa</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['by_payer'] as $row)
                <tr>
                    <td>{{ $row['payer_name'] }}</td>
                    <td class="num">{{ $row['denial_count'] }}</td>
                    <td class="num">{{ $fmt($row['denied_amount']) }}</td>
                    <td class="num">{{ $fmt($row['recovered_amount']) }}</td>
                    <td class="num"><strong>{{ $row['recovery_rate'] }}%</strong></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nenhuma glosa no período.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Evolução mensal</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Mês</th>
                <th class="num">Glosas</th>
                <th style="width:35%">&nbsp;</th>
                <th class="num">Glosado</th>
                <th class="num">Recuperado</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['by_month'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['denial_count'] }}</td>
                    <td>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:{{ (int) round($row['denied_amount'] / $maxMonth * 100) }}%"></div>
                        </div>
                    </td>
                    <td class="num">{{ $fmt($row['denied_amount']) }}</td>
                    <td class="num">{{ $fmt($row['recovered_amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nenhuma glosa no período.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Gerado por GlosasAI em {{ $generatedAt->format('d/m/Y \à\s H:i') }}
        &nbsp;·&nbsp; Documento gerencial, sem valor fiscal.
    </div>
</body>
</html>
