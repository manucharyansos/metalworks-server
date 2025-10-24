<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Created</title>
</head>
<body style="margin: 0; padding: 0">
<table style="margin: auto; border-radius: 20px; width: 100%; max-width: 600px;">
    <tr>
        <td style="background: #9cc8e3; border-top-left-radius: 20px; border-top-right-radius: 20px; padding: 10px; text-align: center; margin: auto;">
            <h1 style="text-align: center; font-family: SansSerif,serif; font-style: italic; font-weight: bold; margin: 0;">Metalwork's New Order Created</h1>
        </td>
    </tr>

    <tr>
        <td style="padding: 20px;">
            <p style="font-family: SansSerif, serif; font-style: italic; font-weight: bold;">A new order has been created for client ID: {{ $order->client_id }}.</p>

            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <th style="text-align: left; padding: 8px;">Order ID</th>
                    <td style="padding: 8px;">{{ $order->id }}</td>
                </tr>
                <tr>
                    <th style="text-align: left; padding: 8px;">Order Status</th>
                    <td style="padding: 8px;">{{ $order->status }}</td>
                </tr>
                <tr>
                    <th style="text-align: left; padding: 8px;">Order Code</th>
                    <td style="padding: 8px;">{{ $order->prefixCode?->code }}</td>
                </tr>
                <tr>
                    <th style="text-align: left; padding: 8px;">Order Number</th>
                    <td style="padding: 8px;">{{ $order->orderNumber?->number }}</td>
                </tr>


                <tr>
                    <th style="text-align: left; padding: 8px;">Name</th>
                    <td style="padding: 8px;">{{ $order->name }}</td>
                </tr>
                <tr>
                    <th style="text-align: left; padding: 8px;">Quantity</th>
                    <td style="padding: 8px;">{{ $order->quantity }}</td>
                </tr>
                <tr>
                    <th style="text-align: left; padding: 8px;">Description</th>
                    <td style="padding: 8px;">{{ $order->description }}</td>
                </tr>
            </table>

            <p style="margin: 20px 0;">You can view the order details <a href="{{ $order->storeLink?->url }}">here</a>.</p>
        </td>
    </tr>

    <tr>
        <td style="display: grid; grid-column: 2; background: #9cc8e3; padding: 10px; text-align: center; border-bottom-left-radius: 20px; border-bottom-right-radius: 20px">
            <div>Metalwork's</div>
            <div>Contact +374 98 025 044</div>
        </td>
    </tr>
</table>
</body>
</html>
