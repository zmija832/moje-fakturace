<x-layouts.guest title="Přihlášení">
    <h2 class="text-xl font-bold">Přihlášení správce</h2>
    <p class="mt-1 text-sm text-slate-600">Pokračujte e-mailem a heslem.</p>

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password">Heslo</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
            @error('password') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <label class="flex items-center gap-2 font-normal">
            <input class="h-4 w-4" type="checkbox" name="remember" value="1">
            Pamatovat přihlášení
        </label>
        <button type="submit" class="button-primary w-full">Přihlásit se</button>
    </form>

    <a href="{{ route('password.request') }}" class="mt-5 block text-center text-sm font-medium text-blue-700 hover:underline">
        Zapomněli jste heslo?
    </a>
</x-layouts.guest>
