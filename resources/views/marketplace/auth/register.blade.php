@extends('marketplace.layout')

@section('title', 'Регистрация покупателя')

@section('content')
    <section class="mp-card" style="max-width:620px;margin:0 auto;">
        <h1 class="mp-page-title" style="font-size:30px;">Регистрация покупателя</h1>
        <p class="mp-page-sub">Создайте аккаунт для заказов, чатов и избранного.</p>

        <form method="post" action="{{ route('marketplace.register.submit', ['marketSlug' => $market->slug]) }}"
              style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px;">
            @csrf
            <label style="display:grid;gap:6px;grid-column:1/-1;">
                <span class="mp-muted">Имя</span>
                <input type="text" name="name" value="{{ old('name') }}" required
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:11px 12px;">
            </label>
            <label style="display:grid;gap:6px;grid-column:1/-1;">
                <span class="mp-muted">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:11px 12px;">
            </label>
            <label style="display:grid;gap:6px;">
                <span class="mp-muted">Пароль</span>
                <input type="password" name="password" required
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:11px 12px;">
            </label>
            <label style="display:grid;gap:6px;">
                <span class="mp-muted">Подтверждение</span>
                <input type="password" name="password_confirmation" required
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:11px 12px;">
            </label>
            <button class="mp-btn mp-btn-brand" type="submit" style="grid-column:1/-1;">Зарегистрироваться</button>
        </form>

        <p class="mp-muted" style="margin:12px 0 0;">
            Уже есть аккаунт?
            <a href="{{ route('marketplace.login', ['marketSlug' => $market->slug]) }}" style="color:#0a84d6;font-weight:700;">Войти</a>
        </p>
    </section>

    <style>
        @media (max-width: 700px) {
            form[action*="/register"] { grid-template-columns: 1fr !important; }
        }
    </style>
@endsection

