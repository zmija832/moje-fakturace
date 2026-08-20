<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRecurringInvoiceRequest;
use App\Models\Business\RecurringInvoiceTemplate;
use App\Services\Business\BusinessDate;
use App\Services\Business\InvoiceFormOptions;
use App\Services\Business\RecurringInvoiceRunner;
use App\Services\Business\RecurringInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecurringInvoiceController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', RecurringInvoiceTemplate::class);

        return view('business.recurring.index', ['templates' => RecurringInvoiceTemplate::query()->with('runs')->orderByDesc('is_active')->orderBy('next_run_on')->paginate(30)]);
    }

    public function create(InvoiceFormOptions $options, BusinessDate $businessDate): View
    {
        Gate::authorize('create', RecurringInvoiceTemplate::class);

        return view('business.recurring.form', ['template' => new RecurringInvoiceTemplate, 'options' => $options->forDate($businessDate->today()->format('Y-m-d'))]);
    }

    public function store(SaveRecurringInvoiceRequest $request, RecurringInvoiceService $service): RedirectResponse
    {
        $template = $service->create($request->validated());

        return redirect()->route('recurring.show', $template->uuid)->with('status', 'Opakovaná faktura byla vytvořena.');
    }

    public function show(string $uuid): View
    {
        $template = $this->find($uuid);
        Gate::authorize('view', $template);

        return view('business.recurring.show', ['template' => $template->load(['items', 'runs.invoice'])]);
    }

    public function edit(string $uuid, InvoiceFormOptions $options): View
    {
        $template = $this->find($uuid);
        Gate::authorize('update', $template);

        return view('business.recurring.form', ['template' => $template->load('items'), 'options' => $options->forDate($template->next_run_on->format('Y-m-d'))]);
    }

    public function update(SaveRecurringInvoiceRequest $request, string $uuid, RecurringInvoiceService $service): RedirectResponse
    {
        $template = $this->find($uuid);
        Gate::authorize('update', $template);
        $service->update($template, $request->validated());

        return redirect()->route('recurring.show', $uuid)->with('status', 'Opakovaná faktura byla upravena.');
    }

    public function pause(string $uuid, RecurringInvoiceService $service): RedirectResponse
    {
        $template = $this->find($uuid);
        Gate::authorize('update', $template);
        $service->setActive($template, false);

        return back()->with('status', 'Opakovaná faktura byla pozastavena.');
    }

    public function resume(string $uuid, RecurringInvoiceService $service): RedirectResponse
    {
        $template = $this->find($uuid);
        Gate::authorize('update', $template);
        $service->setActive($template, true);

        return back()->with('status', 'Opakovaná faktura byla obnovena.');
    }

    public function run(string $uuid, RecurringInvoiceRunner $runner, RecurringInvoiceService $service): RedirectResponse
    {
        $template = $this->find($uuid);
        Gate::authorize('run', $template);
        $run = $runner->run($template);
        $service->auditManualRun($template, $run);

        return back()->with('status', "Běh skončil stavem {$run->status}.");
    }

    private function find(string $uuid): RecurringInvoiceTemplate
    {
        return RecurringInvoiceTemplate::query()->where('uuid', $uuid)->firstOrFail();
    }
}
