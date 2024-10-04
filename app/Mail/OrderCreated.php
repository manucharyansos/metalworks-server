<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderCreated extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $orderUrl;

    /**
     * Create a new message instance.
     */
    public function __construct($order, $orderUrl)
    {
        $this->order = $order;
        $this->orderUrl = $orderUrl;
    }

    /**
     * Build the message.
     */
    public function build(): OrderCreated
    {
        return $this->markdown('emails.orders.created')
            ->subject('New Order Created')
            ->with([
                'order' => $this->order,
                'orderUrl' => $this->orderUrl,
            ]);
    }
}
