<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 2.5cm 2cm; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10.5pt;
            line-height: 1.6;
            color: #1a1a1a;
        }
        .letterhead {
            border-bottom: 2px solid #1e3a5f;
            padding-bottom: 10px;
            margin-bottom: 24px;
        }
        .clinic-name { font-size: 14pt; font-weight: bold; color: #1e3a5f; }
        .clinic-meta { font-size: 8.5pt; color: #555; margin-top: 3px; }
        .body-text { white-space: pre-wrap; text-align: justify; }
        .footer {
            margin-top: 36px;
            padding-top: 8px;
            border-top: 1px solid #ccc;
            font-size: 8pt;
            color: #777;
        }
        .signature { margin-top: 48px; }
        .signature-line {
            border-top: 1px solid #333;
            width: 60%;
            padding-top: 4px;
            font-size: 9pt;
        }
    </style>
</head>
<body>
    <div class="letterhead">
        <div class="clinic-name">{{ $clinic->name }}</div>
        <div class="clinic-meta">
            @if($clinic->cnpj) CNPJ {{ $clinic->cnpj }} @endif
            @if($clinic->cro) &nbsp;·&nbsp; {{ $clinic->cro }} @endif
            @if($clinic->phone) &nbsp;·&nbsp; {{ $clinic->phone }} @endif
            @if($clinic->email) &nbsp;·&nbsp; {{ $clinic->email }} @endif
            @if($clinic->address) <br>{{ $clinic->address }} @endif
        </div>
    </div>

    <div class="body-text">{{ $text }}</div>

    <div class="signature">
        <div class="signature-line">
            Assinatura e carimbo do responsável técnico
        </div>
    </div>

    <div class="footer">
        Guia nº {{ $denial->claimItem?->claim?->claim_number }}
        &nbsp;·&nbsp; Glosa #{{ $denial->id }}
        &nbsp;·&nbsp; Código {{ $denial->reason_code ?? 'não informado' }}
        &nbsp;·&nbsp; Documento gerado em {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
