<x-layouts.app title="Nastavení">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Administrace</p>
        <h1 class="mt-1 text-2xl font-bold">Nastavení účtu</h1>
    </div>

    <section class="card max-w-2xl">
        <h2 class="text-lg font-bold">Změna hesla</h2>
        <form method="POST" action="{{ route('password.update') }}" class="mt-5 space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label for="current_password">Současné heslo</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
                @error('current_password', 'updatePassword') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password">Nové heslo</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
                @error('password', 'updatePassword') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation">Nové heslo znovu</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
            <button type="submit" class="button-primary">Uložit nové heslo</button>
        </form>
    </section>
</x-layouts.app>
