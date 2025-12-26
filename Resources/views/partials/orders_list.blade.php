<div class="panel-heading">
    <h4 class="panel-title">
        <a data-toggle="collapse" href=".em-collapse-orders">
            {{ __("Recent Orders") }}
            <b class="caret"></b>
        </a>
    </h4>
</div>
<div class="em-collapse-orders panel-collapse collapse in">
    <div class="panel-body">
        <div class="sidebar-block-header2"><strong>{{ __("Recent Orders") }}</strong> (<a data-toggle="collapse" href=".em-collapse-orders">{{ __('close') }}</a>)</div>
       	<div id="em-loader">
        	<img src="{{ asset('img/loader-tiny.gif') }}" />
        </div>
        	
        @if (!$load)
            @if (count($orders))
			    <ul class="sidebar-block-list em-orders-list">
                    @foreach($orders as $order)
                        @php
                            $has_cs_requests = !empty($order['cs_requests']) && is_array($order['cs_requests']) && count($order['cs_requests']) > 0;
                        @endphp
                        <li class="em-order-item" data-order-index="{{ $loop->index }}" style="cursor: pointer;">
                            <div>
                                <a href="javascript:void(0)" class="em-order-link">#{{ $order['number'] }}</a>
                                @if ($has_cs_requests)
                                    <span class="label label-info" style="margin-left: 5px; font-size: 10px; padding: 2px 5px;" title="{{ __('Has CS Requests') }}">CS</span>
                                @endif
                                <span class="pull-right">{{ $order['currency'] }} {{ $order['total'] }}</span>
                            </div>
                            <div>
                                <small class="text-help">{{ \Everymarket::formatDate($order['created_at']) }}</small>
                                <small class="pull-right @if (in_array($order['payment_state'] ?? '', ['paid', 'refunded', 'partially_refunded'])) text-success @else text-warning @endif ">
                                    {{ __(ucfirst(str_replace('_', ' ', $order['payment_state'] ?? 'pending'))) }}
                                </small>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{-- Store orders data for JavaScript --}}
                <script type="application/json" id="em-orders-data">
                    {!! json_encode($orders) !!}
                </script>
                <input type="hidden" value="{{$shop_url}}" id="em-shop-url" />

                <div class="margin-top-10 em-refresh small">
                    <a href="#" class="sidebar-block-link"><i class="glyphicon glyphicon-refresh"></i> {{ __("Refresh") }}</a>
                </div>
			@else
			    <div class="text-help margin-top-10 em-no-orders">{{ __("No orders found") }}</div>
                <div class="margin-top-10 em-search small">
                    <a href="#" class="sidebar-block-link"><i class="glyphicon glyphicon-search"></i> {{ __("Search Customer") }}</a>
                </div>
			@endif
        @endif

    </div>
</div>