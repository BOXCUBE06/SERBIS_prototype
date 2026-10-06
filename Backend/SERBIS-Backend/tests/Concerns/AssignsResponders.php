<?php

namespace Tests\Concerns;

use App\Models\Responder;
use App\Models\ServiceRequest;

/**
 * Moving a request to Responding needs at least one available responder on
 * it (ServiceRequestController::update). Tests about something else that
 * dispatch along the way use this to meet the rule, not to skip it.
 */
trait AssignsResponders
{
    /** A fresh available responder, attached to the request. One per call, so two dispatches never share a crew. */
    protected function assignResponder(ServiceRequest|int $request): Responder
    {
        $responder = Responder::create([
            'name' => 'Test Responder',
            'contact_no' => '09170000000',
            'position' => 'Logistics',
            'status' => 'available',
        ]);

        $responder->serviceRequests()->attach(
            $request instanceof ServiceRequest ? $request->getKey() : $request,
            ['assigned_at' => now()]
        );

        return $responder;
    }
}
