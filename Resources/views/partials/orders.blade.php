<div class="conv-sidebar-block">
    <div class="panel-group accordion accordion-empty">
        <div class="panel panel-default @if ($load) em-loading @endif" id="em-orders">
            @include('everymarket::partials/orders_list')
        </div>
    </div>
</div>

@section('javascript')
    @parent
    initEverymarket({!! json_encode($customer_emails) !!}, {{ (int)$load }});
@endsection