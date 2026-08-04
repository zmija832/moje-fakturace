<?php

namespace App\Policies\Business;

use App\Enums\InvoiceStatus;
use App\Models\Business\Invoice;
use App\Models\User;

class InvoicePolicy extends BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function updateAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ?Invoice $invoice = null): bool
    {
        return $this->canManage($user)
            && ($invoice === null || $invoice->status === InvoiceStatus::Draft);
    }

    public function issue(User $user, ?Invoice $invoice = null): bool
    {
        return $this->canManage($user)
            && ($invoice === null || $invoice->status === InvoiceStatus::Draft);
    }

    public function print(User $user, Invoice $invoice): bool
    {
        return $this->canView($user) && $invoice->status === InvoiceStatus::Issued;
    }

    public function downloadPdf(User $user, Invoice $invoice): bool
    {
        return $this->print($user, $invoice);
    }

    public function generatePdf(User $user, Invoice $invoice): bool
    {
        return $this->canManage($user) && $invoice->status === InvoiceStatus::Issued;
    }

    public function sendEmail(User $user, Invoice $invoice): bool
    {
        return $this->canManage($user) && $invoice->status === InvoiceStatus::Issued;
    }

    public function viewPayments(User $user, ?Invoice $invoice = null): bool
    {
        return $this->canView($user)
            && ($invoice === null || $invoice->status === InvoiceStatus::Issued);
    }

    public function recordPayment(User $user, ?Invoice $invoice = null): bool
    {
        return $this->canManage($user)
            && ($invoice === null || $invoice->status === InvoiceStatus::Issued);
    }

    public function reversePayment(User $user, ?Invoice $invoice = null): bool
    {
        return $this->recordPayment($user, $invoice);
    }
}
