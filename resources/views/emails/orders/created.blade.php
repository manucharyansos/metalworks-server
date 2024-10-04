<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Created</title>
</head>
<body>
<h1>Metalworks New Order Created</h1>
<p>A new order has been created for client ID: {{ $order->client_id }}.</p>
<p>Order details:</p>
<table>
    <tr>
        <th>Order ID</th>
        <td>{{ $order->id }}</td>
    </tr>
    <tr>
        <th>Order Status</th>
        <td>{{ $order->status->status }}</td>
    </tr>
    <tr>
        <th>Status</th>
        <td>{{ $order->status->status }}</td>
    </tr>
</table>
<p>You can view the order details <a href="{{ $orderUrl }}">here</a>.</p>
</body>
</html>


