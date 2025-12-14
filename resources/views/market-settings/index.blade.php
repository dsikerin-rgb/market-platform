{{-- resources/views/market-settings/index.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Настройки Рынка</h1>

    <form action="{{ route('market-settings.update') }}" method="POST">
        @csrf
        @method('PUT')

        <h2>Локации рынка</h2>
        <!-- Поля для редактирования локаций рынка -->

        <h2>Типы локаций</h2>
        <!-- Поля для редактирования типов локаций -->

        <h2>Торговые места</h2>
        <!-- Поля для редактирования торговых мест -->

        <h2>Типы мест</h2>
        <!-- Поля для редактирования типов мест -->

        <button type="submit">Сохранить изменения</button>
    </form>
</div>
@endsection
