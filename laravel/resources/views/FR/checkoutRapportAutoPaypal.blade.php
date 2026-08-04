<!DOCTYPE html>
<html lang="fr">

<head>
        <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://thesmartlookup.com//img/logo/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="https://thesmartlookup.com//img/logo/favicon.svg" />
    <link rel="shortcut icon" href="https://thesmartlookup.com//img/logo/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="https://thesmartlookup.com//img/logo/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="https://thesmartlookup.com/" />
    <link rel="manifest" href="https://thesmartlookup.com//img/logo/site.webmanifest" />

    <script>
        (function(w, d, s, l, i) {
            w[l] = w[l] || [];
            w[l].push({
                'gtm.start': new Date().getTime(),
                event: 'gtm.js'
            });
            var f = d.getElementsByTagName(s)[0],
                j = d.createElement(s),
                dl = l != 'dataLayer' ? '&l=' + l : '';
            j.async = true;
            j.src =
                'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
            f.parentNode.insertBefore(j, f);
        })(window, document, 'script', 'dataLayer', 'dd');
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Thesmartlookup - Checkout</title>
    <link href__="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('css/menu.css') }}">
    <link rel="stylesheet" href="{{ asset('css/checkout.css') }}">
    <link rel="stylesheet" href="{{ asset('css/footer.css') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Import des icônes Lucide -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- ===== PayPal (Hosted Card Fields + Apple Pay / PayPal wallet) ===== -->
    <style>
      .pp-field { height: 48px; display:flex; align-items:center; padding:0 12px; }
      .pp-field > * { width:100%; }
      #pp-wallets { margin-top:14px; }
      .pp-divider { display:flex; align-items:center; text-align:center; color:#6b7280; font-size:12px; margin:16px 0 12px; }
      .pp-divider::before, .pp-divider::after { content:""; flex:1; height:1px; background:#e6e8ee; }
      .pp-divider span { padding:0 10px; }
      apple-pay-button { display:block; width:100%; --apple-pay-button-height:46px; --apple-pay-button-border-radius:10px; margin-bottom:10px; }
      paypal-button { display:block; width:100%; min-height:46px; }
    </style>
    <script type="application/json" fncls="fnparams-dede7cc5-15fd-4c75-a9f4-36c430ee3a99" id="fconfig">{"f":"{{ $ppCmid }}","s":"membership-checkout-page","sandbox":{{ $ppIsSandbox ? 'true' : 'false' }}}</script>
    <script>window.__ppSdkLoaded=false;function onPayPalWebSdkLoaded(){window.__ppSdkLoaded=true;if(window.__ppInit){window.__ppInit();}}</script>
    <script async src="{{ $ppSdkHost }}/web-sdk/v6/core" onload="onPayPalWebSdkLoaded()"></script>
</head>

<body>
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=dd" height="0" width="0"
            style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    @include('FR.include.menuRapportAutoFrParCours')

    <!-- Main Container -->
    <div class="main-container">

        <div class="checkout-grid ">
            <!-- LEFT COLUMN - Payment Form -->
            <div class="checkout-div left">
                <div class="payment-card card-white">
                    <div class="popular-card">
                        <div class="popular-header">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                fill="#fff" stroke="#fff" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-star-icon lucide-star">
                                <path
                                    d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                            </svg>
                            Le plus populaire
                        </div>

                        <div class="popular-body">
                            <div class="popular-info">
                                <div class="radio-circle-visual"></div>
                                <div>
                                    <h3 class="popular-title">
                                        RAPPORT COMPLET
                                        <span class="popular-bonus">+ 1 rapport offert</span>
                                    </h3>
                                    <p class="popular-subtitle">Accès immédiat à l'historique intégral</p>
                                </div>
                            </div>

                            <div class="price-box">
                                <div class="price-label">Total à payer aujourd'hui</div>
                                <div class="price-amount">EUR 2.90</div>
                            </div>
                        </div>
                    </div>
                    @if (session('error_pay'))
                        <div class="alert-danger error_payment rounded-pill"
                            style="margin-bottom: 25px; border: 1px solid red; background: #ffbdbd; display: flex; justify-content: center; padding: 7px; border-radius: 5px;">
                            <svg xmlns="http://www.w3.org/2000/svg" style="color: red;" fill="none"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"
                                width="25" height="25">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                            </svg>
                            <p style="color: red;">{{ session('error_pay') }}</p>
                        </div>
                    @endif


                    @if (session('error'))
                        <div class="alert-danger error_payment rounded-pill"
                            style="margin-bottom: 25px; border: 1px solid red; background: #ffbdbd; display: flex; justify-content: center; padding: 7px; border-radius: 5px;">
                            <svg xmlns="http://www.w3.org/2000/svg" style="color: red;" fill="none"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"
                                width="25" height="25">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                            </svg>
                            <p style="color: red;">{{ session('error') }}</p>

                        </div>
                    @endif
                    <!-- Payment Form -->
                    <form id="payment-form" method="POST" action="{{ route('paiement.controller') }}">
                        @csrf
                        <input type="hidden" name="lang" id="lang" value="fr">
                        <input type="hidden" name="service_type" id="service_type" value="vin_lookup">
                        <input type="hidden" name="ip_client" id="ip_client">
                        <input type="hidden" name="query" id="query" value="{{ session('input__main') }}">

                        <input type="hidden" name="returnRoute" id="returnRoute" value="checkout.rapport.auto.fr">
                        <div class="form-group">
                            <label class="form-label" for="email">Adresse email <span class="span-label">(recevez
                                    vos
                                    accès de connexion ici)</span></label>
                            <input type="email" id="email" name="email" class="form-input"
                                placeholder="votre@email.com">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="cardCvc">Nom</label>
                                <input class="form-input" type="text" id="last-name" name="last_name"
                                    placeholder="Dupont" autocomplete="family-name">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="cardExpiry">Prénom</label>
                                <input class="form-input" type="text" id="first-name" name="first_name"
                                    placeholder="Jean" autocomplete="given-name">
                            </div>
                        </div>

                        <div class="form-group" style="display: none;">
                            <label class="form-label" for="cardNumber">Montant (€)</label>
                            <input class="form-input" type="number" id="amount" min="0.01" step="0.01"
                                value="2.9">
                        </div>


                        <div class="form-group">
                            <label class="form-label" for="cardNumber">Numéro de la carte</label>
                            <div id="pp-number" class="form-input pp-field"></div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="cardExpiry">Date d'expiration</label>
                                <div id="pp-expiry" class="form-input pp-field"></div>

                            </div>
                            <div class="form-group">
                                <label class="form-label" for="cardCvc">CVC</label>
                                <div id="pp-cvv" class="form-input pp-field"></div>
                            </div>
                        </div>

                        <div class="checkbox-wrapper">

                            <label class="checkbox-label">
                                <input type="checkbox" id="acceptTerms" name="acceptTerms" class="checkbox-input">

                                <span class="checkbox-text">
                                    Je reconnais avoir lu et accepté sans réserve les <a
                                        href="{{ route('fr.cgv') }}">conditions générale de vente</a>, la
                                    <a href="{{ route('fr.politique.confidentialite') }}">politique de confidentialité</a> et les
                                    conditions tarifaires indiquées incluant une
                                    période d'essaie. J'accepte que le service commence immédiatement et, de ce fait,
                                    renonce expressément à mon droit de rétractation une fois le service execute. Vous
                                    pouvez contacter notre support par e-mail à l'adresse suivante :<a
                                        href="mailto:contact@exemple.com">contact@Thesmartlookup.ai</a>
                                </span>

                            </label>
                            <style>
                                .checkbox-text {
                                    font-size: 10px !important;
                                    color: #4B5563;
                                    line-height: 12px !important;
                                }

                                .pricing-terms p {
                                    font-size: 10px !important;
                                    color: #4B5563;
                                    line-height: 14px !important;
                                    text-align: center;
                                }

                                @media (max-width: 769px) {
                                    .checkbox-text {
                                        font-size: 10px !important;
                                        color: #4B5563;
                                        line-height: 12px;
                                    }

                                    .pricing-terms p {
                                        font-size: 9px !important;
                                        color: #4B5563;
                                        line-height: 13px !important;
                                        text-align: left;
                                    }
                                }
                            </style>
                        </div>
                        <div id="error-message"
                            style="color: red; display: none; font-size: 12px; margin-top: 5px;margin-left:5px;">
                            <span class="text-center">Veuillez cocher la case pour accepter les conditions
                                générales de vente avant de continuer.</span>
                        </div>
                        <div id="erroraccepte"
                            style="display:none; color:red; font-size:12px; margin-top:5px;font-style: italic;
                        text-align: center;">
                            Veuillez cocher cette case afin de pouvoir finaliser votre paiement.
                        </div>

                        <div id="submit_wrapper">
                            <button type="submit" id="submit-button" class="submit-btn">
                                <span id="btnText">Obtenir Mon Rapport</span>
                                <div id="btnSpinner" class="submit-spinner" style="display:none;"></div>
                            </button>
                        </div>

                        <div id="error-email" class="div-error" style="display: none;">
                            <span class="text-center">
                                Cette adresse email possède déjà un abonnement actif. Veuillez utiliser une autre
                                adresse
                                email.
                            </span>
                        </div>

                        <div class="payment-logos">
                            <img src="{{ asset('img/visa.png') }}" alt="Visa">
                            <img src="{{ asset('img/msc.jpg') }}" alt="MasterCard">
                            <img src="{{ asset('img/amex.png') }}" alt="Amex">
                            <div class="secure-badge">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2">
                                    </rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                <span>SECURE PAYMENT</span>
                            </div>
                        </div>
                        <div id="pp-wallets" hidden>
                            <div class="pp-divider"><span>ou payer avec</span></div>
                            <apple-pay-button id="applepay-button" buttonstyle="black" type="plain" hidden></apple-pay-button>
                            <paypal-button id="paypal-button" type="pay" hidden></paypal-button>
                        </div>
                    </form>


                </div>
            </div>

            <!-- RIGHT COLUMN - Report Summary -->
            <div class="checkout-div right">
                <!-- Report Ready Card -->
                <div class="report-card" @if (session('userWithoutRepport')) style="display: none !important;" @endif>
                    <div class="report-header">
                        <div class="report-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                            </svg>
                            <span>Rapport Certifié</span>
                        </div>
                        <h2 class="report-title">Votre Rapport est Prêt</h2>
                        <p class="report-subtitle">Données sécurisées et vérifiées</p>
                    </div>

                    <!-- Avatar -->
                    <div class="report-avatar">
                        <p class="avatar-label">Recherche Pour</p>
                        <p class="avatar-query gradient-text" id="phoneNumber">{{ session('input__main') }}</p>

                        @if (session('logo_voiture') !== 'modelNull' && session('logo_voiture') !== null)
                            <div class="car-icon-box-image" style="background: #fff;">
                                <img src="{{ session('logo_voiture') }}" alt=""
                                    onerror="this.style.display='none'; this.onerror=null;">
                            </div>
                        @endif

                        <img class="img-voiture"
                            src="{{ session('image_voiture') ?? asset('img/logo/covered-red-left.webp') }}"
                            alt="Photo véhicule"
                            onerror="this.onerror=null;this.src='{{ asset('img/logo/covered-red-left.webp') }}';" />
                    </div>

                    <style>
                        .img-voiture {
                            max-width: 80%;
                            height: auto;
                            margin: 0.25rem auto 0 auto;
                            border-radius: 0.25rem;
                        }

                        .car-icon-box-image {
                            width: 20%;
                        }

                        @media (max-width: 768px) {
                            .img-voiture {
                                max-width: 100%;
                                height: auto;
                                margin: 0.25rem auto 0 auto;
                                border-radius: 0.25rem;
                            }

                            .car-icon-box-image {
                                width: 30%;
                            }
                        }
                    </style>

                    <!-- Results Grid -->
                    <div class="results-section">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                                <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                            </svg>
                            Les résultats peuvent inclure :
                        </h3>
                        <div class="results-grid" id="resultsGrid">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>
                </div>

                <!-- Summary Card -->
                <div
                    class="summary-card card-white @if (session('userWithoutSubscription')) marginTopMobile @endif 
                                                    @if (session('userWithoutRepport')) marginTopMobile @endif">
                    <div class="summary-header">
                        <div class="summary-header-bg"></div>
                        <div class="summary-header-content">
                            <div class="summary-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                            </div>
                            <h3 class="summary-title gradient-text">Résumé</h3>
                        </div>
                    </div>

                    <div class="summary-list" id="summaryList">
                        <!-- Populated by JavaScript -->
                    </div>

                    <div class="pricing-terms">
                        <p class="p-pricing-termes-web">
                            Le paiement doit être effectué par Visa ou Mastercard.
                            Profitez d’une période d’essai de 2 jours, effective dès l’achat du rapport pour 2,90€.
                            À l’issue de celle-ci, un prélèvement mensuel de 49,50 € sera effectué jusqu’à résiliation.
                        </p>
                        <p class="p-pricing-termes-mobile">
                            Le paiement doit être effectué par Visa ou Mastercard.<br>
                            Profitez d’une période d’essai de 2 jours, effective dès l’achat du rapport <br>
                            pour 2,90€. À l’issue de celle-ci, un prélèvement mensuel de <br>
                            49,50 € sera effectué jusqu’à résiliation.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!---scroller page version web--->
    <style>
        /* Optionnel : rendre la colonne gauche sticky jusqu’à la fin */
        .checkout-div.left {
            position: sticky;
            top: 100px;
            /* décale un peu du haut de la fenêtre */
            align-self: start;
        }

        @media(max-width : 768px) {
            .checkout-div.left {
                position: relative;
                top: 0;
                /* décale un peu du haut de la fenêtre */
                align-self: start;
            }
        }
    </style>
    <script>
        const left = document.querySelector('.checkout-div.left');
        const right = document.querySelector('.checkout-div.right');

        right.addEventListener('scroll', () => {
            const leftBottom = left.scrollHeight - left.clientHeight;
            if (right.scrollTop > leftBottom) {
                left.scrollTop = right.scrollTop - leftBottom;
            }
        });
    </script>
    <!----End scroller page version web----->

    @php
        $routeFr = route('fr.paiement.rapport.auto');
        $routeEn = route('en.paiement'); 
    @endphp

    @include('FR.include.footerRapportAutoFr')


    <style>
        .report-card {
            border-radius: 16px;
            padding: 24px;
            border: 2px solid #DBEAFE;
            background: white !important;
            margin-bottom: 32px;
        }
    </style>

    <script>
        // Data
        const possibleResults = [{
                label: "Fiche technique"
            },
            {
                label: "Historique kilométrage"
            },
            {
                label: "Accidents & Vols"
            },
            {
                label: "Gages & Oppositions"
            },
            {
                label: "Historique propriétaires"
            },
            {
                label: "Équipements & Options"
            },
            {
                label: "Pollution & Crit'Air"
            },
            {
                label: "Contrôles techniques"
            }
        ];

        const summaryItems = [{
                icon: '<polyline points="20 6 9 17 4 12"></polyline>',
                title: "Rapport Véhicule Complet",
                description: "Accédez à un rapport détaillé basé sur les données administratives officielles et les référentiels techniques constructeur."
            },

            {
                icon: '<ellipse cx="12" cy="5" rx="9" ry="3" /> <path d="M3 5V19A9 3 0 0 0 21 19V5" /> <path d="M3 12A9 3 0 0 0 21 12" />',
                title: "Données administratives officielles",
                description: "Informations issues des bases d’immatriculation : date de mise en circulation, type de véhicule, puissance fiscale et caractéristiques déclarées."
            },
            {
                icon: '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2" /> <circle cx="7" cy="17" r="2" /><path d="M9 17h6" /> <circle cx="17" cy="17" r="2" />',
                title: "Informations techniques détaillées",
                description: "Fiche technique complète du véhicule : motorisation, cylindrée, puissance, carburant, transmission et codes moteur."
            },
            {
                icon: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" />',
                title: "Données constructeur",
                description: "Identification précise du modèle et de sa variante via les bases constructeur et TecDoc, utile pour les pièces compatibles."
            },
            {
                icon: '<path d="m12 14 4-4"></path> <path d="M3.34 19a10 10 0 1 1 17.32 0"></path>',
                title: "Historique du kilométrage",
                description: "Analyse des relevés de kilométrage accessibles afin de détecter d’éventuelles incohérences."
            },
            {
                icon: '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"></path><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"></path>',
                title: "Émissions & Taxes",
                description: "Consultation des données environnementales (CO₂, énergie) et estimation indicative des éléments fiscaux."
            },
            {
                icon: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
                title: "Accès à votre espace personnel",
                description: "Profitez d'un accès à notre service dès votre inscription, effective après validation de votre paiement, directement depuis votre espace personnel."
            }
        ];

        // Get phone from URL
        const urlParams = new URLSearchParams(window.location.search);
        const phoneNumber = urlParams.get('phone') || "{{ session('input__main') }}";
        document.getElementById('phoneNumber').textContent = phoneNumber;

        // Render results
        function renderResults() {
            const container = document.getElementById('resultsGrid');
            container.innerHTML = possibleResults.map(result => `
        <div class="result-item">
          <div class="result-check">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
          </div>
          <span class="result-label">${result.label}</span>
        </div>
      `).join('');
        }

        // Render summary
        function renderSummary() {
            const container = document.getElementById('summaryList');
            container.innerHTML = summaryItems.map(item => `
        <div class="summary-item">
          <div class="summary-item-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${item.icon}</svg>
          </div>
          <div>
            <h4 class="summary-item-title">${item.title}</h4>
            <p class="summary-item-desc">${item.description}</p>
          </div>
        </div>
      `).join('');
        }

        // Format card number
        function formatCardNumber(value) {
            const v = value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
            const matches = v.match(/\d{4,16}/g);
            const match = (matches && matches[0]) || '';
            const parts = [];

            for (let i = 0; i < match.length; i += 4) {
                parts.push(match.substring(i, i + 4));
            }

            return parts.length ? parts.join(' ') : value;
        }
        renderResults();
        renderSummary();
    </script>

    <!-- Script pour initialiser les icônes -->
    <script>
        lucide.createIcons();
    </script>

<script>
(function () {
  var PP = { clientId:@json($ppClientId), clientToken:@json($ppClientToken), cmid:@json($ppCmid), currency:@json($ppCurrency), api:'/paypal' };
  var form = document.getElementById('payment-form');
  var emailInput = document.getElementById('email');
  var lastName = document.getElementById('last-name');
  var firstName = document.getElementById('first-name');
  var submitButton = document.getElementById('submit-button');
  var acceptTerms = document.getElementById('acceptTerms');
  var cardSession = null;

  function setError(el){ if(!el) return; el.style.border='2px solid #dc3545'; el.style.boxShadow='0 0 5px rgba(220,53,69,.5)'; }
  function clearError(el){ if(!el) return; el.style.border=''; el.style.boxShadow=''; }
  [emailInput,lastName,firstName].forEach(function(i){ if(i){ i.addEventListener('input',function(){clearError(this);}); } });
  acceptTerms.addEventListener('change',function(){ if(this.checked){ document.getElementById('erroraccepte').style.display='none'; } });

  function spinner(on){
    document.getElementById('btnText').style.display = on ? 'none' : '';
    document.getElementById('btnSpinner').style.display = on ? 'inline-block' : 'none';
    submitButton.disabled = on;
  }
  function validate(){
    var ok = true;
    document.getElementById('error-message').style.display='none';
    document.getElementById('erroraccepte').style.display='none';
    document.getElementById('error-email').style.display='none';
    var email = emailInput.value.trim();
    if(!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ setError(emailInput); ok=false; }
    if(!lastName.value.trim()){ setError(lastName); ok=false; }
    if(!firstName.value.trim()){ setError(firstName); ok=false; }
    if(!acceptTerms.checked){ document.getElementById('erroraccepte').style.display='block'; ok=false; }
    return ok;
  }
  function showErr(txt){
    var d = document.getElementById('error-email');
    d.innerHTML = '<span class="text-center">'+txt+'</span>';
    d.style.display='block';
    d.scrollIntoView({behavior:'smooth',block:'center'});
  }
  function getAttribution(){
    var KEY='sl_first_touch';
    try{ var s=JSON.parse(localStorage.getItem(KEY)||'null'); if(s){ return s; } }catch(e){}
    var q=new URLSearchParams(location.search);
    var a={ source:q.get('utm_source'), medium:q.get('utm_medium'), campaign:q.get('utm_campaign'),
      content:q.get('utm_content'), term:q.get('utm_term'), gclid:q.get('gclid'), fbclid:q.get('fbclid'),
      referrer:document.referrer||null, landing_page:location.href };
    try{ localStorage.setItem(KEY,JSON.stringify(a)); }catch(e){}
    return a;
  }
  // Payment succeeded -> hand off to the existing provisioning controller
  // (creates the account + welcome email + redirects to the login page).
  function provision(){
    fetch('https://api.ipify.org?format=json').then(function(r){return r.json();})
      .then(function(d){ document.getElementById('ip_client').value=d.ip; })
      .catch(function(){ document.getElementById('ip_client').value='N/A'; })
      .finally(function(){ HTMLFormElement.prototype.submit.call(form); });
  }
  // Never charge a card we cannot provision: check the email first.
  function precheckEmail(email){
    return fetch(PP.api+'/precheck-email?email='+encodeURIComponent(email))
      .then(function(r){return r.json();}).then(function(d){ return !!d.available; })
      .catch(function(){ return true; });
  }

  async function payWithCard(){
    if(!validate()){ return; }
    if(!cardSession){ showErr("Le module de paiement charge encore, merci de reessayer dans un instant."); return; }
    spinner(true);
    try{
      var email = emailInput.value.trim();
      var available = await precheckEmail(email);
      if(!available){ spinner(false); showErr("Cette adresse email possede deja un abonnement actif. Veuillez utiliser une autre adresse email."); return; }
      var st = await (await fetch(PP.api+'/create-setup-token',{method:'POST'})).json();
      if(!st.id){ throw new Error(st.error||'setup token'); }
      var result = await cardSession.submit(st.id);
      if(result && result.state && result.state!=='succeeded'){
        if(result.state==='canceled'){ throw new Error("Authentification de la carte annulee."); }
        throw new Error((result.data&&result.data.message)||"Carte refusee.");
      }
      var fin = await (await fetch(PP.api+'/finalize',{method:'POST',headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ setup_token: st.id, email: email, cmid: PP.cmid, attribution: getAttribution() })})).json();
      if(fin.error){
        if(fin.error==='trial_limit_reached'){ throw new Error("Cette carte a deja ete utilisee pour la periode d'essai."); }
        throw new Error(fin.message||fin.error||"Paiement refuse.");
      }
      provision();
    }catch(e){
      spinner(false);
      showErr("Le paiement n'a pas pu etre finalise : "+(e&&e.message?e.message:e));
    }
  }

  form.addEventListener('submit', function(e){ e.preventDefault(); payWithCard(); });

  // ----- Wallets (Apple Pay / PayPal) -----
  function createWalletOrder(method){
    return fetch(PP.api+'/create-wallet-order',{method:'POST',headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ method:method, return_url:location.href, cancel_url:location.href, brand_name:'TheSmartLookup', cmid:PP.cmid, email:emailInput.value.trim(), attribution:getAttribution() })})
      .then(function(r){return r.json();}).then(function(d){ if(!d.order_id){ throw new Error(d.error||'order'); } return {orderId:d.order_id}; });
  }
  async function captureWallet(orderId){
    var fin = await (await fetch(PP.api+'/capture-order',{method:'POST',headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ order_id:orderId, email:emailInput.value.trim(), attribution:getAttribution() })})).json();
    if(fin.error){ showErr("Paiement echoue : "+(fin.message||fin.error)); return; }
    provision();
  }
  async function initWallets(){
    if(!PP.clientToken){ return; }
    try{
      var wsdk = await window.paypal.createInstance({ clientToken: PP.clientToken, components:['paypal-payments'], pageType:'checkout' });
      var methods = await wsdk.findEligibleMethods({ currencyCode: PP.currency, paymentFlow:'VAULT_WITH_PAYMENT' });
      var opts = { savePayment:true,
        onApprove: async function(data){ await captureWallet(data.orderId); },
        onCancel: function(){},
        onError: function(err){ showErr("Erreur de paiement : "+(err&&err.message?err.message:err)); } };
      function guard(fn){ return function(){ if(!validate()){ return; } fn(); }; }
      var shown=false;
      if(methods.isEligible('paypal')){
        var ppS = wsdk.createPayPalOneTimePaymentSession(opts);
        var b=document.getElementById('paypal-button'); b.removeAttribute('hidden');
        b.addEventListener('click', guard(function(){ ppS.start({presentationMode:'auto'}, createWalletOrder('paypal')).catch(function(e){ showErr('PayPal: '+(e&&e.message?e.message:e)); }); }));
        shown=true;
      }
      if(methods.isEligible('applepay') && typeof wsdk.createApplePayOneTimePaymentSession==='function'){
        var apS = wsdk.createApplePayOneTimePaymentSession(opts);
        var ab=document.getElementById('applepay-button'); ab.removeAttribute('hidden');
        ab.addEventListener('click', guard(function(){ apS.start({presentationMode:'auto'}, createWalletOrder('apple_pay')).catch(function(e){ showErr('Apple Pay: '+(e&&e.message?e.message:e)); }); }));
        shown=true;
      }
      if(shown){ document.getElementById('pp-wallets').removeAttribute('hidden'); }
    }catch(e){ if(window.console){ console.log('wallet init skipped', e); } }
  }

  window.__ppInit = async function(){
    try{
      var sdk = await window.paypal.createInstance({ clientId: PP.clientId, components:['card-fields'] });
      var methods = await sdk.findEligibleMethods({ currencyCode: PP.currency });
      if(!methods.isEligible('advanced_cards')){ showErr("Le paiement par carte n'est pas disponible pour le moment."); return; }
      cardSession = sdk.createCardFieldsSavePaymentSession();
      document.getElementById('pp-number').appendChild(cardSession.createCardFieldsComponent({type:'number', placeholder:'1234 5678 9012 3456'}));
      document.getElementById('pp-expiry').appendChild(cardSession.createCardFieldsComponent({type:'expiry', placeholder:'MM/AA'}));
      document.getElementById('pp-cvv').appendChild(cardSession.createCardFieldsComponent({type:'cvv', placeholder:'CVC'}));
    }catch(e){ showErr("Le module de paiement n'a pas pu demarrer."); }
    initWallets();
  };
  if(window.__ppSdkLoaded){ window.__ppInit(); }

  @if(session('error_pay'))
    showErr(@json(session('error_pay')));
    setError(emailInput);
  @endif
})();
</script>

<style>
/* Style pour la div d'erreur */
.div-error {
    background-color: #fee;
    border: 1px solid #fcc;
    border-radius: 4px;
    padding: 12px;
    margin: 10px 0;
    animation: shake 0.5s;
}

.div-error span {
    color: #c33;
    font-size: 14px;
    display: block;
    text-align: center;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}

/* Style pour le spinner */
.submit-spinner {
    border: 3px solid #f3f3f3;
    border-top: 3px solid #3498db;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    animation: spin 1s linear infinite;
    display: inline-block;
    vertical-align: middle;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Transition douce pour les bordures */
.form-input {
    transition: border 0.3s ease, box-shadow 0.3s ease;
}
</style>
    <script>
        // Menu Mobile
        const menuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        menuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
            const icon = mobileMenu.classList.contains('hidden') ? 'menu' : 'x';
            menuBtn.innerHTML = `<i data-lucide="${icon}" class="w-5 h-5"></i>`;
            lucide.createIcons();
        });

        // Simple animation on scroll
        const observerOptions = {
            root: null,
            rootMargin: '0px',
            threshold: 0.1
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Animate elements on scroll
        document.querySelectorAll(
                '.step-card, .feature-item, .testimonial-card, .source-card, .benefit-card, .account-card, .faq-item')
            .forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(el);
            });
    </script>

    <style>
        .span-label {
            font-size: 14px;
        }

        .form-input.error {
            border: 2px solid #e95e5e !important;
            background-color: #fff5f5;
        }

        .form-input.stripe-error {
            border: 2px solid #e95e5e !important;
            background-color: #fff5f5;
        }

        .stripe-error {
            border: 1px solid red;
            border-radius: 10px;
            text-align: center;
            padding: 8px;
            margin-bottom: 10px;
            color: red;
            background-color: #fff3f3;
        }

        input[type="checkbox"].error {
            outline: 2px solid red;
            width: 12px;
            border-radius: 22px;
            height: 12px;
            margin-top: 4px;
            background: red;
            border-color: red;
        }

        .price-amount {
            font-size: 28px;
            font-weight: 700;
            color: #000;
        }

        .pricing-terms p {
            font-size: 10px !important;
            color: #4B5563;
            line-height: 14px !important;
            text-align: center;
        }

        @media(max-width: 768px) {
            .span-label {
                font-size: 13px;
            }

            .pricing-terms p {
                font-size: 9px !important;
                color: #4B5563;
                line-height: 13px !important;
                text-align: left;
            }

            .price-amount {
                font-size: 24px;
                font-weight: 700;
                color: #000;
            }
        }
    </style>

    <style>
        .popular-card {
            margin-bottom: 2rem;
            border-radius: 0.75rem;
            border: 2px solid #0066FF;
            overflow: hidden;
            background-color: white;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            font-family: sans-serif;
            /* ou votre police par défaut */
        }

        .popular-header {
            background-color: #0066FF;
            padding: 0.5rem 0;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
        }

        .popular-body {
            padding: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .popular-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .radio-circle-visual {
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            border: 6px solid #0f172a;
            /* Couleur foncée */
            background-color: white;
            flex-shrink: 0;
        }

        .popular-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.25;
            display: flex;
            flex-direction: column;
            margin: 0;
        }

        .popular-bonus {
            font-weight: 400;
            color: #64748b;
            font-size: 0.875rem;
        }

        /* Responsive pour le titre */
        @media (min-width: 640px) {
            .popular-title {
                flex-direction: row;
                align-items: center;
                gap: 0.5rem;
            }
        }

        .popular-subtitle {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 0.125rem;
            margin-bottom: 0;
        }

        .price-box {
            text-align: right;
            flex-shrink: 0;
        }

        .price-label {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            margin-bottom: 0.125rem;
        }

        .price-amount {
            font-size: 1.5rem;
            font-weight: 900;
            color: #0f172a;
        }

        .div-error {
            color: red;

            font-size: 14px;
            margin-top: 5px;
            margin-left: 5px;
            border: 1px solid red;
            padding: 12px 0px;
            text-align: center;
            border-radius: 10px;
            background: #fff2f2;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            .popular-body {
                padding: 1.25rem;
                display: block;
                /* align-items: center; */
                justify-content: space-between;
                gap: 1rem;
            }

            .price-box {
                margin-left: 39px;
                text-align: left;
                flex-shrink: 0;
                margin-top: 15px;
            }
        }
    </style>

    <script type="text/javascript" src="https://c.paypal.com/da/r/fb.js"></script>
</body>

</html>
