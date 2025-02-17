@props([
    'plugin'
])

<div class="col-md-4">
    <div class="ticketBox fixed" style="padding-top:0px; overflow: hidden; margin-bottom: 25px;">
        <div class="row">
            <div class="col-md-12 tw-p-none tw-overflow-hidden tw-mb-m tw-max-h-[150px]">
                <img src="{{ $plugin->getPluginImageData() }}" width="100" height="100" class="tw-rounded tw-mx-base tw-mt-base tw-float-left"/>

                @if($plugin instanceof \Leantime\Domain\Plugins\Models\MarketplacePlugin)
                    <div
                        class="certififed label-default tw-absolute tw-top-[10px] tw-right-[10px] tw-text-primary tw-rounded-full tw-text-sm"
                        data-tippy-content="{{ __('marketplace.certified_tooltip') }}"
                    >
                        <i class="fa fa-certificate"></i>
                        Certified
                    </div>
                @endif
                <div class="" style="margin-top:40px;">
                    @if (! empty($plugin->name))
                        <h5 class="subtitle">{!! $plugin->name !!} {{ $plugin->version ? "(v".$plugin->version.")" : "" }}<br /></h5>
                        <x-global::inlineLinks :links="$plugin->getMetadataLinks()" />
                    @endif
                </div>
            </div>
        </div>
        <div class="row tw-mb-base">
            <div class="col tw-flex tw-flex-col tw-gap-base">

                @if (! empty($desc = $plugin->getCardDesc()))
                    <p>{!! $desc !!}</p>
                @endif
                <div class="tw-flex tw-flex-row tw-gap-base">
                    <div class="plugin-price tw-flex-1 tw-content-center" >
                        <strong>{!! $plugin->getPrice() !!}</strong><br />
                        @if($plugin->getCalulatedMonthlyPrice() !== '')
                         <small style="font-style: italic;">{!! $plugin->getCalulatedMonthlyPrice() !!}</small>
                        @endif
                    </div>
                    <div class="tw-border-t tw-border-[var(--main-border-color)] tw-px-base tw-text-right tw-flex-1 tw-justify-items-end">
                        @include($plugin->getControlsView(), ["plugin" => $plugin])
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
