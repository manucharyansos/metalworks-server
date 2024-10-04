<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderShipped extends Mailable implements ShouldQueue
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
    public function build(): OrderShipped
    {
        return $this->markdown('emails.orders.shipped')
            ->subject('Your Order Has Shipped')
            ->with([
                'order' => $this->order,
                'orderUrl' => $this->orderUrl,
            ]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order Shipped',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.shipped',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
