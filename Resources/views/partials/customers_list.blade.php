@if (!$load)
    @if (count($customers))
	    <ul class="sidebar-block-list em-orders-list">
            @foreach($customers as $order)
                <li class="em-order-item" data-order-index="{{ $loop->index }}" style="cursor: pointer;">
                    <div>
                        <a href="javascript:void(0)" class="em-order-link">#{{ $order['number'] }}</a>
                        <span class="pull-right">{{ $order['currency'] }} {{ $order['total'] }}</span>
                    </div>
                    <div>
                        <small class="text-help">{{ \Everymarket::formatDate($order['created_at']) }}</small>
                        <small class="pull-right @if (in_array($order['payment_state'] ?? '', ['paid', 'refunded', 'partially_refunded'])) text-success @else text-warning @endif ">
                            {{ __(ucfirst(str_replace('_', ' ', $order['payment_state'] ?? 'pending'))) }}
                        </small>
                    </div>
                    <div>
                        <small class="text-help">{{ $order['email'] }}</small>
                        <div class="pull-right small">
                            <a href="#" class="sidebar-block-link" data-customer-email="{{ $order['email'] }}"><i class="glyphicon glyphicon-refresh"></i> Add Email</a>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
	@else
	    <div class="text-help margin-top-10 em-no-orders">{{ __("No customers found") }}</div>
	@endif
@endif