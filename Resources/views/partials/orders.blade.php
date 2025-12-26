<div class="conv-sidebar-block">
    <div class="panel-group accordion accordion-empty">
        <div class="panel panel-default @if ($load) em-loading @endif" id="em-orders">
            @include('everymarket::partials/orders_list')
        </div>
    </div>
</div>

{{-- Slide-out panel for order details --}}
<div id="em-order-panel" class="em-order-panel">
    <div class="em-panel-overlay"></div>
    <div class="em-panel-content">
        <div class="em-panel-header">
            <button class="em-panel-close" aria-label="Close">&times;</button>
            <div class="em-panel-logo">
                <svg width="20" height="20" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M25.9 8.4c0-.1 0-.1-.1-.2 0 0 0-.1-.1-.1L20.5.7c-.1-.1-.2-.2-.3-.2h-.2L19.8.4c-.1 0-.2.1-.3.1L15.2.2c0 0 0 0-.1 0L12.8 0c-.1 0-.3.1-.4.2L7.6 8.1c0 .1-.1.1-.1.2 0 0 0 .1-.1.1v.2c0 .1 0 .2.1.3l7.7 22.5c0 .1.1.2.2.2.1 0 .2.1.3.1h.2c.1 0 .2 0 .3-.1.1 0 .2-.1.2-.2L24 8.9c0-.1 0-.2.1-.3v-.2c-.1 0-.1 0-.2 0zM19.9 2.2l3.7 6.1h-3.7V2.2zm-7.8 0v6.1h-3.7l3.7-6.1zm3.9 0v6.1h-3.7l1.8-6.1h1.9zm4.1 0v6.1h-3.7l1.8-6.1h1.9zM16 10.5l-6.8 19.8-6.4-19.8h13.2zm.2 0h13.2l-6.4 19.8L16.2 10.5z" fill="#95BF47"/>
                </svg>
                <span id="em-panel-title">Order #<span class="order-number"></span></span>
            </div>
        </div>
        <div class="em-panel-body" id="em-panel-body">
            {{-- Order details will be injected here via JavaScript --}}
        </div>
    </div>
</div>

<div id="em-search-panel" class="em-search-panel">
    <div class="em-panel-overlay"></div>
    <div class="em-panel-content">
        <div class="em-panel-header">
            <button class="em-panel-close" aria-label="Close">&times;</button>
            <div class="em-panel-logo">
                <svg width="20" height="20" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M25.9 8.4c0-.1 0-.1-.1-.2 0 0 0-.1-.1-.1L20.5.7c-.1-.1-.2-.2-.3-.2h-.2L19.8.4c-.1 0-.2.1-.3.1L15.2.2c0 0 0 0-.1 0L12.8 0c-.1 0-.3.1-.4.2L7.6 8.1c0 .1-.1.1-.1.2 0 0 0 .1-.1.1v.2c0 .1 0 .2.1.3l7.7 22.5c0 .1.1.2.2.2.1 0 .2.1.3.1h.2c.1 0 .2 0 .3-.1.1 0 .2-.1.2-.2L24 8.9c0-.1 0-.2.1-.3v-.2c-.1 0-.1 0-.2 0zM19.9 2.2l3.7 6.1h-3.7V2.2zm-7.8 0v6.1h-3.7l3.7-6.1zm3.9 0v6.1h-3.7l1.8-6.1h1.9zm4.1 0v6.1h-3.7l1.8-6.1h1.9zM16 10.5l-6.8 19.8-6.4-19.8h13.2zm.2 0h13.2l-6.4 19.8L16.2 10.5z" fill="#95BF47"/>
                </svg>
                <span id="em-panel-title">Search Customer</span>
            </div>
        </div>
        <div class="em-panel-body" id="em-panel-body">
            <div class="em-detail-section">
                <div class="form-group">
                    <div class="row">
                        <div class="col-sm-8">
                            <input id="em-search-content" style="height: 30px;" type="text" class="form-control input-sized-lg" name="em-search-content">
                        </div>

                        <div class="col-sm-4" style="padding-left: 0px;" >
                            <button type="submit" class="btn btn-primary" id="em-search-btn">
                                Search
                            </button>
                        </div>
                    </div>
                    <span class="em-line-item-sku">* Search by Order Number or Email</span>


                    <div id="em-customers">
                        <div id="em-loader">
                            <img src="{{ asset('img/loader-tiny.gif') }}" />
                        </div>
                        <div class="em-customers-list">

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@section('javascript')
    @parent
    initEverymarket({!! json_encode($customer_emails) !!}, {{ (int)$load }}, {{ isset($customer) && $customer ? $customer->id : 'null' }}, '{{ Auth::user()->email ?? '' }}');
@endsection