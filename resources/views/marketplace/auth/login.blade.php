@extends('marketplace.layout')

@section('title', 'Вход покупателя')

@section('content')
    <section class="mp-card" style="max-width:560px;margin:0 auto;">
        <h1 class="mp-page-title" style="font-size:30px;">Вход покупателя</h1>
        <p class="mp-page-sub">Войдите, чтобы сохранять избранное и общаться с арендаторами.</p>

        <form method="post" action="{{ route('marketplace.login.submit', ['marketSlug' => $market->slug]) }}" style="display:grid;gap:10px;margin-top:14px;">
            @csrf
            <label style="display:grid;gap:6px;">
                <span class="mp-muted">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:11px 12px;">
            </label>
            <label style="display:grid;gap:6px;">
                <span class="mp-muted">Пароль</span>
                <input type="password" name="password" required
                       style="border:1px solid #cbdcf3;border-radius:12px;padding:11px 12px;">
            </label>
            <button class="mp-btn mp-btn-brand" type="submit">Войти</button>
        </form>

        <p class="mp-muted" style="margin:12px 0 0;">
            Нет аккаунта?
            <a href="{{ route('marketplace.register', ['marketSlug' => $market->slug]) }}" style="color:#0a84d6;font-weight:700;">Зарегистрироваться</a>
        </p>
    </section>
@endsection

