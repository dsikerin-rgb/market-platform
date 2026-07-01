<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Демо-доступ к Market Platform</title>
    <meta name="description" content="Демо-версия SaaS-сервиса для управления рынком: арендаторы, места, карта, договоры, задолженность, кабинет арендатора и marketplace.">
    <style>
        :root {
            color-scheme: light;
            --ink: #172033;
            --muted: #5d6b82;
            --line: #d7dee8;
            --paper: #ffffff;
            --soft: #f5f7fa;
            --accent: #0f8b6f;
            --accent-dark: #08624f;
            --warm: #c7791f;
            --danger-safe: #b45309;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: Arial, "Helvetica Neue", sans-serif;
            line-height: 1.5;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            min-height: 100vh;
            background: var(--soft);
        }

        .hero {
            min-height: 82vh;
            display: flex;
            flex-direction: column;
            color: #ffffff;
            background-image:
                linear-gradient(90deg, rgba(13, 24, 38, 0.92) 0%, rgba(13, 24, 38, 0.76) 48%, rgba(13, 24, 38, 0.34) 100%),
                url("{{ asset('marketplace/announcements/promo-farm-flavors.jpg') }}");
            background-position: center;
            background-size: cover;
        }

        .nav,
        .section-inner {
            width: min(1120px, calc(100% - 40px));
            margin: 0 auto;
        }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 22px 0;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .brand-mark {
            width: 34px;
            height: 34px;
            border: 2px solid rgba(255, 255, 255, 0.72);
            border-radius: 8px;
            display: grid;
            place-items: center;
            font-size: 16px;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-link,
        .button {
            border-radius: 8px;
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            white-space: nowrap;
        }

        .nav-link {
            padding: 0 14px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: rgba(255, 255, 255, 0.9);
        }

        .hero-body {
            width: min(1120px, calc(100% - 40px));
            margin: 0 auto;
            flex: 1;
            display: grid;
            align-content: center;
            padding: 40px 0 72px;
        }

        .hero-content {
            width: min(720px, 100%);
        }

        .eyebrow {
            margin: 0 0 16px;
            color: #b9f3df;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        h1 {
            margin: 0;
            font-size: clamp(34px, 6vw, 64px);
            line-height: 1.02;
            letter-spacing: 0;
        }

        .hero-copy {
            width: min(630px, 100%);
            margin: 22px 0 0;
            color: rgba(255, 255, 255, 0.86);
            font-size: 19px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 30px;
        }

        .button {
            padding: 0 20px;
            border: 1px solid transparent;
            cursor: pointer;
            font: inherit;
        }

        .button-primary {
            background: #ffffff;
            color: #0d1826;
        }

        .button-secondary {
            border-color: rgba(255, 255, 255, 0.34);
            color: #ffffff;
        }

        .inline-entry-form {
            margin: 0;
        }

        .hero-facts {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 34px;
            width: min(700px, 100%);
        }

        .fact {
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 14px;
            background: rgba(255, 255, 255, 0.08);
        }

        .fact strong {
            display: block;
            font-size: 22px;
        }

        .fact span {
            display: block;
            color: rgba(255, 255, 255, 0.78);
            font-size: 13px;
        }

        .section {
            padding: 56px 0;
            background: var(--paper);
        }

        .section.alt {
            background: var(--soft);
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            gap: 28px;
            align-items: end;
            margin-bottom: 28px;
        }

        h2 {
            margin: 0;
            font-size: clamp(26px, 4vw, 40px);
            line-height: 1.1;
            letter-spacing: 0;
        }

        .section-lead {
            max-width: 520px;
            margin: 0;
            color: var(--muted);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .feature,
        .plan,
        .step {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #ffffff;
            padding: 20px;
        }

        .feature-label {
            color: var(--accent-dark);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        .feature h3,
        .plan h3,
        .step h3 {
            margin: 8px 0 8px;
            font-size: 18px;
            line-height: 1.25;
        }

        .feature p,
        .plan p,
        .step p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        .plans {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .plan.highlight {
            border-color: rgba(15, 139, 111, 0.45);
            box-shadow: inset 0 4px 0 var(--accent);
        }

        .plan ul,
        .safety-list {
            margin: 16px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .plan li,
        .safety-list li {
            position: relative;
            padding-left: 20px;
            color: var(--muted);
        }

        .plan li::before,
        .safety-list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0.65em;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
        }

        .safety-band {
            background: #172033;
            color: #ffffff;
        }

        .safety-band .section-lead,
        .safety-list li {
            color: rgba(255, 255, 255, 0.78);
        }

        .safety-list li::before {
            background: #f2b84b;
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .offer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            align-items: start;
        }

        .offer-card {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #ffffff;
            padding: 20px;
        }

        .offer-card h3 {
            margin: 0 0 12px;
            font-size: 18px;
            line-height: 1.25;
        }

        .offer-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .offer-list li {
            color: var(--muted);
            display: grid;
            grid-template-columns: 28px 1fr;
            gap: 10px;
            align-items: start;
        }

        .offer-list strong {
            color: var(--accent-dark);
        }

        .offer-note {
            margin-top: 16px;
            border-radius: 8px;
            background: #eef7f4;
            color: #075c4b;
            padding: 14px;
            font-weight: 700;
        }

        .step-number {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            background: #e8f5f1;
            color: var(--accent-dark);
            font-weight: 800;
        }

        .final {
            background: #f8efe3;
        }

        .final-layout {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 24px;
            align-items: center;
        }

        .contact-panel {
            border: 1px solid #e7cda8;
            border-radius: 8px;
            background: #fffaf3;
            padding: 22px;
        }

        .contact-panel p {
            margin: 0 0 16px;
            color: #6c4d22;
        }

        .contact-panel .button {
            width: 100%;
            background: var(--warm);
            color: #ffffff;
        }

        .demo-entry-form {
            margin: 0 0 12px;
        }

        .demo-entry-note {
            font-size: 13px;
        }

        .request-form {
            display: grid;
            gap: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-field {
            display: grid;
            gap: 6px;
        }

        .form-field.full {
            grid-column: 1 / -1;
        }

        .form-label {
            color: #513915;
            font-size: 13px;
            font-weight: 700;
        }

        .form-field input,
        .form-field select,
        .form-field textarea {
            width: 100%;
            min-height: 42px;
            border: 1px solid #e7cda8;
            border-radius: 8px;
            background: #ffffff;
            color: var(--ink);
            font: inherit;
            padding: 9px 11px;
        }

        .form-field textarea {
            min-height: 96px;
            resize: vertical;
        }

        .form-field input:focus,
        .form-field select:focus,
        .form-field textarea:focus {
            border-color: var(--warm);
            outline: 2px solid rgba(199, 121, 31, 0.16);
            outline-offset: 1px;
        }

        .consent-row {
            display: grid;
            grid-template-columns: 18px 1fr;
            gap: 10px;
            align-items: start;
            color: #6c4d22;
            font-size: 13px;
        }

        .consent-row input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
        }

        .form-status,
        .form-errors {
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 14px;
        }

        .form-status {
            border: 1px solid #9dd6c8;
            background: #edf9f5;
            color: #075c4b;
        }

        .form-status--warning {
            border-color: #f0c36d;
            background: #fff8e7;
            color: #7a4a05;
        }

        .form-errors {
            border: 1px solid #f0b7a5;
            background: #fff2ed;
            color: #87331a;
        }

        .form-errors ul {
            margin: 0;
            padding-left: 18px;
        }

        .honeypot {
            position: absolute;
            left: -10000px;
            top: auto;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }

        @media (max-width: 860px) {
            .nav {
                align-items: flex-start;
                flex-direction: column;
            }

            .hero {
                min-height: auto;
            }

            .hero-body {
                padding: 52px 0 66px;
            }

            .hero-facts,
            .feature-grid,
            .plans,
            .offer-grid,
            .steps,
            .final-layout,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .section-head {
                display: block;
            }

            .section-lead {
                margin-top: 12px;
            }
        }

        @media (max-width: 520px) {
            .nav,
            .section-inner,
            .hero-body {
                width: min(100% - 28px, 1120px);
            }

            .nav-actions,
            .hero-actions {
                width: 100%;
            }

            .nav-link,
            .hero-actions .button {
                flex: 1 1 100%;
            }

            .hero-copy {
                font-size: 17px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <header class="hero">
        <nav class="nav" aria-label="Главная навигация">
            <a class="brand" href="{{ route('demo.landing') }}" aria-label="Market Platform demo">
                <span class="brand-mark">MP</span>
                <span>Market Platform</span>
            </a>
            <div class="nav-actions">
                <a class="nav-link" href="{{ route('login') }}">Войти</a>
                <a class="nav-link" href="#offer">КП</a>
                <a class="nav-link" href="#demo-request">Заявка</a>
            </div>
        </nav>

        <div class="hero-body">
            <div class="hero-content">
                <p class="eyebrow">SaaS для управляющих рынками</p>
                <h1>Демо-доступ к системе управления рынком</h1>
                <p class="hero-copy">
                    Покажите арендаторов, места, карту, договоры, задолженность и кабинет арендатора на безопасных синтетических данных.
                    Для первых клиентов можно начать с демо или ограниченной бесплатной версии.
                </p>
                <div class="hero-actions" aria-label="Основные действия">
                    <a class="button button-primary" href="#demo-request">Открыть демо</a>
                    <a class="button button-secondary" href="{{ route('login') }}">Войти в сервис</a>
                </div>
                <div class="hero-facts" aria-label="Что входит в демо">
                    <div class="fact">
                        <strong>15 минут</strong>
                        <span>сценарий первого показа</span>
                    </div>
                    <div class="fact">
                        <strong>4 роли</strong>
                        <span>директор, админ, оператор, арендатор</span>
                    </div>
                    <div class="fact">
                        <strong>0 live</strong>
                        <span>1C и внешние интеграции выключены</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main>
        <section class="section">
            <div class="section-inner">
                <div class="section-head">
                    <h2>Что можно показать клиенту</h2>
                    <p class="section-lead">Демо собрано вокруг типовой работы рынка: от карты и договоров до задолженности и кабинета арендатора.</p>
                </div>
                <div class="feature-grid">
                    <article class="feature">
                        <div class="feature-label">Директор</div>
                        <h3>Финансы и занятость</h3>
                        <p>Сводка по начислениям, платежам, долгам, свободным местам и операционным отклонениям.</p>
                    </article>
                    <article class="feature">
                        <div class="feature-label">Администратор</div>
                        <h3>Арендаторы и договоры</h3>
                        <p>Единая карточка арендатора, привязанные места, договоры, статусы и история взаимодействия.</p>
                    </article>
                    <article class="feature">
                        <div class="feature-label">Оператор</div>
                        <h3>Карта рынка</h3>
                        <p>Фигуры торговых мест связаны с карточками мест, арендаторами и финансовыми сигналами.</p>
                    </article>
                    <article class="feature">
                        <div class="feature-label">Арендатор</div>
                        <h3>Личный кабинет</h3>
                        <p>Договоры, начисления, платежи, задолженность, документы и витрина товаров в одном интерфейсе.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section alt">
            <div class="section-inner">
                <div class="section-head">
                    <h2>Как лучше привлекать первых клиентов</h2>
                    <p class="section-lead">Оптимальная схема: сначала безопасное демо, затем пилот с ограниченным набором данных клиента.</p>
                </div>
                <div class="plans">
                    <article class="plan highlight">
                        <h3>Демо-версия</h3>
                        <p>Подходит для первого знакомства и онлайн-презентации.</p>
                        <ul>
                            <li>Синтетические арендаторы, места, договоры и финансы.</li>
                            <li>Без live 1C, почты, Telegram и webhook.</li>
                            <li>Доступ для директора, администратора, оператора и арендатора.</li>
                        </ul>
                    </article>
                    <article class="plan">
                        <h3>Ограниченный пилот</h3>
                        <p>Подходит после демо, когда клиент готов проверить сервис на своей структуре.</p>
                        <ul>
                            <li>Один рынок или часть объекта.</li>
                            <li>Ограниченный импорт арендаторов, мест и договоров.</li>
                            <li>Live-интеграции только после staging-проверки и backup.</li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        <section id="offer" class="section">
            <div class="section-inner">
                <div class="section-head">
                    <h2>Презентация и коммерческое предложение</h2>
                    <p class="section-lead">Клиент сразу понимает, что увидит на показе, как устроен пилот и чем ограничена бесплатная версия.</p>
                </div>
                <div class="offer-grid">
                    <article class="offer-card">
                        <h3>Сценарий показа на 15 минут</h3>
                        <ol class="offer-list">
                            <li><strong>1</strong><span>Карта рынка: свободные места, долги, служебные зоны и групповые места на островах.</span></li>
                            <li><strong>2</strong><span>Арендаторы, договоры, ставки, начисления, платежи и карточка места.</span></li>
                            <li><strong>3</strong><span>Рабочие роли: директор, администратор, оператор и арендатор.</span></li>
                            <li><strong>4</strong><span>Переход к пилоту: какие данные нужны и какие интеграции подключаются позже.</span></li>
                        </ol>
                    </article>
                    <article class="offer-card">
                        <h3>Что входит в пилот</h3>
                        <ul class="offer-list">
                            <li><strong>•</strong><span>Один рынок или ограниченная зона объекта без переноса всего бизнеса сразу.</span></li>
                            <li><strong>•</strong><span>Импорт мест, арендаторов, договоров и стартовых финансовых остатков.</span></li>
                            <li><strong>•</strong><span>Настройка ролей сотрудников и безопасная проверка интеграции с 1C.</span></li>
                            <li><strong>•</strong><span>Короткий итоговый отчёт: что готово к запуску, что требует ручной сверки.</span></li>
                        </ul>
                    </article>
                </div>
                <div class="offer-note">
                    Бесплатная версия ограничивается демо-данными или узким пилотным контуром. Live 1C и боевые записи включаются только после staging-проверки, backup и отдельного согласования.
                </div>
            </div>
        </section>

        <section class="section safety-band">
            <div class="section-inner">
                <div class="section-head">
                    <h2>Безопасность демо</h2>
                    <p class="section-lead">Демо-контур нужен для продаж без риска для prod и бухгалтерских данных клиента.</p>
                </div>
                <ul class="safety-list">
                    <li>Демо-данные создаются через отдельный `demo:provision` и помечаются как synthetic.</li>
                    <li>Reset удаляет только известные demo-записи и не трогает реальные рынки.</li>
                    <li>Live 1C, mail, Telegram и webhooks в демо выключены preflight-guard.</li>
                    <li>Prod data writes и flags включаются только отдельным решением после проверки.</li>
                </ul>
            </div>
        </section>

        <section class="section">
            <div class="section-inner">
                <div class="section-head">
                    <h2>Путь подключения</h2>
                    <p class="section-lead">Переход от демо к реальному клиенту должен быть коротким, но контролируемым.</p>
                </div>
                <div class="steps">
                    <article class="step">
                        <div class="step-number">1</div>
                        <h3>Показ демо</h3>
                        <p>15-минутная демонстрация на безопасном демо-рынке с готовыми ролями и данными.</p>
                    </article>
                    <article class="step">
                        <div class="step-number">2</div>
                        <h3>Пилот</h3>
                        <p>Ограниченный контур на части данных клиента, без изменения продовой бухгалтерии.</p>
                    </article>
                    <article class="step">
                        <div class="step-number">3</div>
                        <h3>Запуск SaaS</h3>
                        <p>Tenant scope, роли, интеграции и регламент поддержки после smoke-проверок.</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="demo-request" class="section final">
            <div class="section-inner final-layout">
                <div>
                    <h2>Запросить демо или ограниченную бесплатную версию</h2>
                    <p class="section-lead">
                        Оставьте запрос на демо-доступ. Для пилота заранее подготовьте список арендаторов, торговых мест,
                        договоров, последние начисления и схему объекта.
                    </p>
                </div>
                <div class="contact-panel">
                    <p>Первый безопасный шаг: показать демо на синтетических данных, затем согласовать пилотный контур.</p>

                    <form class="demo-entry-form request-form" method="post" action="{{ route('demo.quick-start') }}">
                        @csrf

                        <div class="honeypot" aria-hidden="true" hidden>
                            <label>
                                Сайт компании
                                <input type="text" name="company_website" tabindex="-1" autocomplete="off">
                            </label>
                        </div>

                        <div class="form-grid">
                            <label class="form-field">
                                <span class="form-label">Имя</span>
                                <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" required>
                            </label>

                            <label class="form-field">
                                <span class="form-label">Город или рынок</span>
                                <input type="text" name="organization" value="{{ old('organization') }}" autocomplete="organization" required>
                            </label>

                            <label class="form-field">
                                <span class="form-label">Email</span>
                                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email">
                            </label>

                            <label class="form-field">
                                <span class="form-label">Телефон</span>
                                <input type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel">
                            </label>
                        </div>

                        <label class="consent-row">
                            <input type="checkbox" name="consent" value="1" @checked(old('consent')) required>
                            <span>Согласен на обработку данных для связи по заявке.</span>
                        </label>

                        <button class="button" type="submit">
                            {{ config('demo_pilot.public_login_enabled') ? 'Открыть демо' : 'Получить доступ к демо' }}
                        </button>

                        <p class="demo-entry-note">
                            {{ config('demo_pilot.public_login_enabled')
                                ? 'После отправки контакта откроется демо-рынок в роли директора. Данные синтетические, внешние интеграции выключены.'
                                : 'Мы сохраним заявку и отправим доступ к демо после проверки контакта.' }}
                        </p>
                    </form>

                    @if (session('demo_quick_start_status') === 'access_pending')
                        <div class="form-status" role="status">
                            Заявка сохранена. Мы отправим доступ к демо после проверки контакта.
                        </div>
                    @elseif (session('demo_request_status') === 'sent' || request()->boolean('request_sent'))
                        <div class="form-status" role="status">
                            Заявка отправлена. Мы свяжемся с вами и согласуем формат показа.
                        </div>
                    @endif

                    @if (session('demo_public_login_status') === 'admin_session_active')
                        <div class="form-status form-status--warning" role="status">
                            Вы уже вошли в админку. Чтобы не переключить текущий рынок, откройте демо в отдельном браузере, инкогнито или выйдите из текущей учётной записи.
                        </div>
                    @elseif (session('demo_public_login_status') === 'already_authenticated')
                        <div class="form-status form-status--warning" role="status">
                            Вы уже вошли в сервис. Чтобы открыть публичное демо, используйте отдельный браузер, инкогнито или выйдите из текущей учётной записи.
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="form-errors" role="alert">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <p class="demo-entry-note">
                        Для пилота или бесплатной версии заполните расширенную заявку ниже.
                    </p>

                    <form class="request-form" method="post" action="{{ route('demo.request') }}">
                        @csrf

                        <div class="honeypot" aria-hidden="true" hidden>
                            <label>
                                Сайт компании
                                <input type="text" name="company_website" tabindex="-1" autocomplete="off">
                            </label>
                        </div>

                        <div class="form-grid">
                            <label class="form-field">
                                <span class="form-label">Имя</span>
                                <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" required>
                            </label>

                            <label class="form-field">
                                <span class="form-label">Организация или рынок</span>
                                <input type="text" name="organization" value="{{ old('organization') }}" autocomplete="organization" required>
                            </label>

                            <label class="form-field">
                                <span class="form-label">Email</span>
                                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
                            </label>

                            <label class="form-field">
                                <span class="form-label">Телефон</span>
                                <input type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel">
                            </label>

                            <label class="form-field">
                                <span class="form-label">Город</span>
                                <input type="text" name="city" value="{{ old('city') }}" autocomplete="address-level2">
                            </label>

                            <label class="form-field">
                                <span class="form-label">Формат</span>
                                <select name="request_type" required>
                                    <option value="demo" @selected(old('request_type', 'demo') === 'demo')>Демо-показ</option>
                                    <option value="pilot" @selected(old('request_type') === 'pilot')>Ограниченный пилот</option>
                                    <option value="free" @selected(old('request_type') === 'free')>Бесплатная версия</option>
                                </select>
                            </label>

                            <label class="form-field">
                                <span class="form-label">Тип объекта</span>
                                <input type="text" name="market_format" value="{{ old('market_format') }}" placeholder="рынок, ТЦ, ярмарка">
                            </label>

                            <label class="form-field">
                                <span class="form-label">Количество мест</span>
                                <input type="number" name="spaces_count" value="{{ old('spaces_count') }}" min="1" max="100000" inputmode="numeric">
                            </label>

                            <label class="form-field full">
                                <span class="form-label">Комментарий</span>
                                <textarea name="message" maxlength="2000">{{ old('message') }}</textarea>
                            </label>
                        </div>

                        <label class="consent-row">
                            <input type="checkbox" name="consent" value="1" @checked(old('consent')) required>
                            <span>Согласен на обработку данных для связи по заявке.</span>
                        </label>

                        <button class="button" type="submit">Отправить заявку</button>
                    </form>
                </div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
