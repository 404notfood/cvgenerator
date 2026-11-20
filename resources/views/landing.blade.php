<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, nofollow' }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="CV Generator">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta name="twitter:card" content="summary">
    <link rel="icon" href="/cv-mark.svg" type="image/svg+xml">
    @fonts
    @vite('resources/css/app.css')
</head>
<body class="cv-landing">
    <a class="cv-skip" href="#contenu">Aller au contenu</a>
    <header class="cv-site-header">
        <div class="cv-container cv-site-nav">
            <a class="cv-wordmark" href="{{ route('home') }}"><img src="/cv-mark.svg" alt="" width="33" height="33">CV Generator</a>
            <nav aria-label="Navigation principale">
                <a class="cv-nav-example" href="#exemple">Exemple</a>
                <a class="cv-nav-login" href="{{ auth()->check() ? route('dashboard') : route('login') }}">{{ auth()->check() ? 'Mon espace' : 'Connexion' }}</a>
            </nav>
        </div>
    </header>
    <main id="contenu">
        <section class="cv-intro cv-container" aria-labelledby="cv-title">
            <h1 id="cv-title">Ton CV, adapté<br> à chaque offre<span>.</span></h1>
            <div class="cv-intro-actions">
                <p>Dépose ton CV et colle le lien de l’annonce. Retrouve une lettre de motivation et un CV ciblés, à relire avant l’envoi.</p>
                <a class="cv-action" href="{{ auth()->check() ? route('dashboard') : ($canRegister ? route('register') : route('login')) }}">Préparer ma candidature <span aria-hidden="true">→</span></a>
                <p class="cv-format-note">PDF et Word · Sans abonnement</p>
            </div>
        </section>
        <section class="cv-container cv-demo-section" id="exemple" aria-label="Exemple interactif de candidature">
            <div class="cv-demo-frame">
                <div class="cv-demo-top">
                    <div>
                        <p>Exemple de candidature</p>
                        <h2>Développeuse web <span>chez Atelier Nord</span></h2>
                    </div>
                    <span class="cv-demo-status"><span aria-hidden="true"></span> À relire</span>
                </div>
                <fieldset class="cv-example">
                    <legend class="sr-only">Afficher un élément de l’exemple</legend>
                    <input class="sr-only" type="radio" id="demo-offer" name="demo-document">
                    <input class="sr-only" type="radio" id="demo-cv" name="demo-document" checked>
                    <input class="sr-only" type="radio" id="demo-letter" name="demo-document">
                    <div class="cv-example-switch">
                        <label for="demo-offer">Offre d’emploi</label>
                        <label for="demo-cv">CV adapté</label>
                        <label for="demo-letter">Lettre <span>de motivation</span></label>
                    </div>
                    <div class="cv-example-panels">
                        <div id="panel-offer" class="cv-example-panel">
                            <div class="cv-example-detail">
                                <h3>L’annonce</h3>
                                <p>Atelier Nord recrute à Lille pour faire évoluer les outils internes de son équipe.</p>
                            </div>
                            <article class="cv-example-document">
                                <p class="cv-doc-subtitle">Atelier Nord · Lille · CDI</p>
                                <h3>Développeuse web</h3>
                                <h4>Les missions</h4>
                                <p>Maintenir un portail client Laravel, écrire des tests automatisés et participer aux revues de code.</p>
                                <h4>Le profil recherché</h4>
                                <p>Une expérience avec PHP et Laravel, le goût du travail en équipe et de la rigueur dans la mise en production.</p>
                            </article>
                        </div>
                        <div id="panel-cv" class="cv-example-panel">
                            <div class="cv-example-detail">
                                <h3>Ce que le CV met en avant</h3>
                                <ul class="cv-example-matches">
                                    <li>Maintenance Laravel</li>
                                    <li>Tests automatisés</li>
                                </ul>
                                <dl class="cv-example-score">
                                    <dt>Score ATS de l’exemple</dt>
                                    <dd><strong>94</strong> / 100</dd>
                                </dl>
                            </div>
                            <article class="cv-example-document">
                                <h3>Camille Martin</h3>
                                <p class="cv-doc-subtitle">Développeuse web · Lille</p>
                                <h4>Profil</h4>
                                <p>Développement d’applications métiers en PHP et Laravel, avec un soin particulier apporté aux tests et à la mise en production.</p>
                                <h4>Expérience professionnelle</h4>
                                <p><strong>Développeuse full stack</strong> · Studio Rivage · 2021–2026</p>
                                <p>Migration d’un portail client sous Laravel et mise en place de tests automatisés.</p>
                            </article>
                        </div>
                        <div id="panel-letter" class="cv-example-panel">
                            <div class="cv-example-detail">
                                <h3>Pour cette entreprise</h3>
                                <p>La lettre reprend le poste, les missions de l’annonce et une expérience concrète du parcours.</p>
                            </div>
                            <article class="cv-example-document">
                                <p class="cv-doc-subtitle">Camille Martin · À l’équipe d’Atelier Nord</p>
                                <h3>Candidature au poste de développeuse web</h3>
                                <p>Bonjour,</p>
                                <p>Chez Studio Rivage, j’ai participé à la migration d’un portail client sous Laravel et mis en place ses tests automatisés. Je souhaite mettre cette expérience au service des outils internes d’Atelier Nord.</p>
                                <p>Je serais ravie d’échanger avec votre équipe sur les évolutions prévues pour le portail.</p>
                            </article>
                        </div>
                    </div>
                </fieldset>
            </div>
        </section>
    </main>
    <footer class="cv-site-footer cv-container">
        <p>CV Generator</p>
        <p>Le score ATS est calculé par CV Generator.</p>
    </footer>
</body>
</html>
