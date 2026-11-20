<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Aperçu du document</title>
    <style>
        @page { margin: {{ $compact ? '29pt 38pt' : '40pt 48pt' }}; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: {{ $compact ? '9.25pt' : '10.5pt' }}; line-height: {{ $cv ? '1.32' : '1.48' }}; color: #253345; }
        p, h1, h2 { margin: 0 0 {{ $compact ? '2pt' : '3pt' }}; overflow-wrap: break-word; }
        .top-rule { width: 30pt; height: 2.5pt; background: #216158; margin-bottom: {{ $compact ? '7pt' : '10pt' }}; }
        h1 { color: #172536; font-size: {{ $compact ? '23pt' : '29pt' }}; line-height: 1.12; margin: 0 0 5pt; page-break-after: avoid; }
        h2 { color: #216158; border-bottom: 0.6pt solid #c0d2cf; font-size: {{ $compact ? '10pt' : '11pt' }}; margin: {{ $compact ? '4pt 0 4pt' : '7pt 0 5pt' }}; padding-bottom: 2pt; page-break-after: avoid; }
        .headline { color: #172536; font-size: {{ $compact ? '11pt' : '13pt' }}; font-weight: bold; page-break-after: avoid; }
        .contact { color: #4a5967; font-size: 9pt; page-break-after: avoid; }
        .position { margin-top: {{ $compact ? '3pt' : '6pt' }}; color: #172536; font-weight: bold; page-break-after: avoid; }
        .bullet { margin-left: 9pt; text-indent: -9pt; }
        .space { height: {{ $cv ? ($compact ? '1.5pt' : '3pt') : '7pt' }}; }
        .paper { display: block; }
        .template-editorial .top-rule { width: 100%; background: #283d6b; }
        .template-editorial h1, .template-editorial .headline, .template-editorial .contact { text-align: center; }
        .template-editorial h1, .template-editorial .headline, .template-editorial .position { color: #202f51; }
        .template-editorial h2 { color: #283d6b; background: #ecf0f7; border-color: #c5cfe1; text-indent: 6pt; }
        .template-minimal .top-rule { background: transparent; }
        .template-minimal h1, .template-minimal h2, .template-minimal .headline, .template-minimal .position { color: #202020; }
        .template-minimal h2 { border-color: #a8a8a8; }
        .template-minimal, .template-minimal .contact { color: #383838; }
        .cv-heading { padding: {{ $compact ? '3pt 12pt 2pt' : '13pt 16pt 11pt' }}; margin-bottom: {{ $compact ? '0' : '10pt' }}; }
        .cv-heading p:last-child { margin-bottom: 0; }
        .cv-columns { width: 100%; table-layout: fixed; border-collapse: collapse; }
        .cv-columns td { vertical-align: top; }
        .cv-main { padding-right: 14pt; }
        .cv-side { width: 31%; padding: 5pt 10pt 8pt; font-size: {{ $compact ? '8.75pt' : '9.25pt' }}; }
        .cv-side p { overflow-wrap: break-word; }
        .cv-side h2 { font-size: 9pt; }
        .template-marine .cv-heading { background: #172e47; border-bottom: 4pt solid #519aa2; }
        .template-marine .cv-heading h1, .template-marine .cv-heading .headline { color: #fff; }
        .template-marine .cv-heading .contact { color: #e1eef3; }
        .template-marine h2 { color: #173853; border-color: #a7c8d0; }
        .template-marine .cv-side { background: #e9f2f6; }
        .template-sauge .cv-heading { background: #e8eee5; border-left: 5pt solid #58735e; }
        .template-sauge h1 { font-family: 'DejaVu Serif', serif; color: #263d30; }
        .template-sauge h2 { color: #304f3b; border-color: #b8c8bb; }
        .template-sauge .cv-side { background: #f1f4ee; border-left: 1pt solid #b8c8bb; }
        .template-sauge .cv-main h2 { background: #e8eee5; border-color: #e8eee5; text-indent: 5pt; }
        .template-terracotta .cv-heading { background: #f7ebe4; border-top: 3pt solid #984e37; border-radius: 0 0 28pt 0; }
        .template-terracotta h1 { color: #663624; font-family: 'DejaVu Serif', serif; font-weight: normal; }
        .template-terracotta .headline, .template-terracotta .position { color: #663624; }
        .template-terracotta h2 { color: #793f2d; border: 0; border-left: 3pt solid #ad634d; background: #f7ebe4; padding: {{ $compact ? '0 0 2pt' : '2pt 0' }}; text-indent: 6pt; border-radius: 0 9pt 9pt 0; }
        .template-signature .cv-heading { text-align: center; background: #fbf7ee; border-bottom: 1pt solid #a37b48; }
        .template-signature h1 { color: #352820; font-family: 'DejaVu Serif', serif; font-style: italic; font-weight: normal; }
        .template-signature .headline { font-size: {{ $compact ? '10pt' : '11pt' }}; color: #543e2a; letter-spacing: 0.6pt; }
        .template-signature h2 { color: #65482f; font-family: 'DejaVu Serif', serif; border-color: #d5bf9e; }
        .template-chronologie .cv-heading { padding-left: 0; padding-right: 0; border-bottom: 2pt solid #19737b; }
        .template-chronologie h1, .template-chronologie h2 { color: #154a52; }
        .template-chronologie h2 { border-bottom-color: #c2dee0; }
        .template-chronologie .position { padding-left: 9pt; border-left: 3pt solid #287f89; background: #e7f2f2; }
        .template-chronologie .bullet { margin-left: 0; padding-left: 19pt; border-left: 1pt solid #b6d3d6; }
        .template-ardoise .cv-heading { padding-left: 0; padding-right: 0; border-bottom: 2pt solid #433b58; }
        .template-ardoise h1, .template-ardoise h2, .template-ardoise .position { color: #30283e; }
        .template-ardoise h2 { border-color: #c9c1d3; }
        .template-ardoise .cv-side { background: #3f364c; color: #fff; }
        .template-ardoise .cv-side h2 { color: #fff; border-color: #807391; }
        .template-studio .cv-heading { padding-left: 0; padding-right: 0; border-top: 2pt solid #7456ae; }
        .template-studio h1 { color: #2c2251; background: #eae3f7; padding: 0 8pt; }
        .template-studio .headline { color: #372760; padding-left: 8pt; }
        .template-studio .contact { text-align: right; }
        .template-studio h2 { color: #392963; border: 0; border-bottom: 2pt solid #d6c9ed; }
        @if ($preview)
            body { padding: 12px; background: #edf1f7; }
            .paper { box-sizing: border-box; width: min(100%, 210mm); min-height: 36rem; aspect-ratio: 210 / 297; padding: {{ $compact ? 'clamp(16px, 5vw, 38pt)' : 'clamp(18px, 6vw, 48pt)' }}; margin-inline: auto; border: 1px solid #dfe5ee; background: #fff; }
            @media (max-width: 440px) {
                body { padding: 8px; font-size: 9.5pt; }
                h1 { font-size: 22pt; }
                .headline { font-size: 11pt; }
                .cv-columns, .cv-columns tbody, .cv-columns tr, .cv-columns td { display: block; width: auto; }
                .cv-main { padding-right: 0; }
                .cv-side { margin-top: 10pt; }
            }
        @endif
    </style>
</head>
<body>
    <main class="paper template-{{ $template }}">
        @if (!in_array($template, ['classique', 'editorial', 'minimal'], true))
            @php
                $headerSize = 0;
                foreach ($lines as $line) {
                    if (!in_array($line['type'], ['name', 'headline', 'contact'], true)) {
                        break;
                    }
                    $headerSize++;
                }
            @endphp
            <header class="cv-heading">
                @include('documents.lines', ['lines' => array_slice($lines, 0, $headerSize)])
            </header>
            @if ($sidebarFrom !== null)
                <table id="cv-columns" class="cv-columns" role="presentation"><tbody><tr>
                    <td class="cv-main">
                        @include('documents.lines', ['lines' => array_slice($lines, $headerSize, $sidebarFrom - $headerSize)])
                    </td>
                    <td class="cv-side">
                        @include('documents.lines', ['lines' => array_slice($lines, $sidebarFrom)])
                    </td>
                </tr></tbody></table>
            @else
                @include('documents.lines', ['lines' => array_slice($lines, $headerSize)])
            @endif
        @else
            <div class="top-rule"></div>
            @include('documents.lines', ['lines' => $lines])
        @endif
    </main>
</body>
</html>
