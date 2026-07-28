<x-layouts.guest title="Nové heslo">
    <h2 class="text-xl font-bold">Nastavení nového hesla</h2>
    <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username">
            @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password">Nové heslo</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
            @error('password') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password_confirmation">Nové heslo znovu</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
        <button type="submit" class="button-primary w-full">Změnit heslo</button>
    </form>
</x-layouts.guest>
