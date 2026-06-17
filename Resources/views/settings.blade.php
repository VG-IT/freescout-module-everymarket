<form class="form-horizontal margin-top margin-bottom" method="POST" action="">
    {{ csrf_field() }}

    @if (isset($settings['mailboxes_enabled']) && count($settings['mailboxes_enabled']))
        <div class="alert alert-warning">
            {{ __('The following mailboxes have Everymarket connection configured:') }}
            <ul>
                @foreach($settings['mailboxes_enabled'] as $mailbox)
                    <li><a href="{{ route('mailboxes.everymarket', ['id' => $mailbox->id]) }}">{{ $mailbox->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="form-group{{ $errors->has('settings.everymarket->shop_domain') ? ' has-error' : '' }}">
        <label class="col-sm-2 control-label">{{ __('Shop Domain') }}</label>

        <div class="col-sm-6">
            <div class="input-group input-sized-lg">
                <span class="input-group-addon input-group-addon-grey">https://</span>
                <input type="text" class="form-control input-sized-lg" name="settings[everymarket.shop_domain]" value="{{ old('settings') ? old('settings')['everymarket.shop_domain'] : $settings['everymarket.shop_domain'] }}" placeholder="everymarket.com">
            </div>

            @include('partials/field_error', ['field'=>'settings.everymarket->shop_domain'])

            <p class="form-help">
                {{ __('Example') }}: everymarket.com
            </p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings.everymarket->api_domain') ? ' has-error' : '' }}">
        <label class="col-sm-2 control-label">{{ __('API Domain') }}</label>

        <div class="col-sm-6">
            <div class="input-group input-sized-lg">
                <span class="input-group-addon input-group-addon-grey">https://</span>
                <input type="text" class="form-control input-sized-lg" name="settings[everymarket.api_domain]" value="{{ old('settings') ? old('settings')['everymarket.api_domain'] : $settings['everymarket.api_domain'] }}" placeholder="api.everymarket.com">
            </div>

            @include('partials/field_error', ['field'=>'settings.everymarket->api_domain'])

            <p class="form-help">
                {{ __('Example') }}: api.everymarket.com
            </p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API Access Token') }}</label>

        <div class="col-sm-6">
            <input type="text" class="form-control input-sized-lg" name="settings[everymarket.access_token]" value="{{ $settings['everymarket.access_token'] }}">
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API Version') }}</label>

        <div class="col-sm-6">
            <input type="text" class="form-control input-sized-lg" name="settings[everymarket.api_version]" value="{{ $settings['everymarket.api_version'] }}" placeholder="v1">
        </div>
    </div>

    <div class="form-group margin-top margin-bottom">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save') }}
            </button>
        </div>
    </div>
</form>