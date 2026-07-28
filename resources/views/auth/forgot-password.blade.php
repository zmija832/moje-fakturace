<x-layouts.guest title="Zapomenuté heslo">
    <h2 class="text-xl font-bold">Obnova hesla</h2>
    <p class="mt-1 text-sm text-slate-600">Zadejte e-mail administrátorského účtu.</p>
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="button-primary w-full">Poslat odkaz pro obnovu</button>
    </form>
    <a href="{{ route('login') }}" class="mt-5 block text-center text-sm font-medium text-blue-700 hover:underline">Zpět na přihlášení</a>
</x-layouts.guest>
