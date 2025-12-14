{{-- resources/views/tasks/show.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Задача: {{ $task->title }}</h1>

    <h2>Комментарии</h2>
    <ul>
        @foreach ($task->comments as $comment)
            <li>{{ $comment->comment }} ({{ $comment->created_at->diffForHumans() }})</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('tasks.addComment', $task->id) }}">
        @csrf
        <input type="text" name="comment" placeholder="Добавить комментарий" />
        <button type="submit">Отправить</button>
    </form>

    <h2>Наблюдатели</h2>
    <ul>
        @foreach ($task->watchers as $watcher)
            <li>{{ $watcher->name }}</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('tasks.addWatcher', $task->id) }}">
        @csrf
        <select name="user_id">
            @foreach ($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }}</option>
            @endforeach
        </select>
        <button type="submit">Назначить наблюдателя</button>
    </form>
</div>
@endsection
