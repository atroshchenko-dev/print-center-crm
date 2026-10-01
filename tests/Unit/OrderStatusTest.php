<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use Tests\TestCase;

/**
 * OrderStatusTest — verifies the status machine defined in OrderStatus enum.
 * Covers TZ §4.3: Adaptive statuses and transition rules.
 */
class OrderStatusTest extends TestCase
{
    public function test_new_allows_in_progress_completed_and_cancelled(): void
    {
        $transitions = OrderStatus::New->allowedTransitions();

        $this->assertContains(OrderStatus::InProgress, $transitions);
        $this->assertContains(OrderStatus::CompletedIssued, $transitions);
        $this->assertContains(OrderStatus::Cancelled, $transitions);
        $this->assertCount(3, $transitions);
    }

    public function test_in_progress_allows_ready_completed_and_cancelled(): void
    {
        $transitions = OrderStatus::InProgress->allowedTransitions();

        $this->assertContains(OrderStatus::Ready, $transitions);
        $this->assertContains(OrderStatus::CompletedIssued, $transitions);
        $this->assertContains(OrderStatus::Cancelled, $transitions);
        $this->assertCount(3, $transitions);
    }

    public function test_ready_allows_paid_completed_cancelled(): void
    {
        $transitions = OrderStatus::Ready->allowedTransitions();

        $this->assertContains(OrderStatus::PaidIssued, $transitions);
        $this->assertContains(OrderStatus::CompletedIssued, $transitions);
        $this->assertContains(OrderStatus::Cancelled, $transitions);
        $this->assertCount(3, $transitions);
    }

    public function test_terminal_statuses_have_no_transitions(): void
    {
        $this->assertEmpty(OrderStatus::PaidIssued->allowedTransitions());
        $this->assertEmpty(OrderStatus::CompletedIssued->allowedTransitions());
        $this->assertEmpty(OrderStatus::Cancelled->allowedTransitions());
    }

    public function test_is_editable_only_for_new_and_in_progress(): void
    {
        $this->assertTrue(OrderStatus::New->isEditable());
        $this->assertTrue(OrderStatus::InProgress->isEditable());

        $this->assertFalse(OrderStatus::Ready->isEditable());
        $this->assertFalse(OrderStatus::PaidIssued->isEditable());
        $this->assertFalse(OrderStatus::CompletedIssued->isEditable());
        $this->assertFalse(OrderStatus::Cancelled->isEditable());
    }

    public function test_is_terminal_for_final_statuses(): void
    {
        $this->assertTrue(OrderStatus::PaidIssued->isTerminal());
        $this->assertTrue(OrderStatus::CompletedIssued->isTerminal());
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());

        $this->assertFalse(OrderStatus::New->isTerminal());
        $this->assertFalse(OrderStatus::InProgress->isTerminal());
        $this->assertFalse(OrderStatus::Ready->isTerminal());
    }
}
