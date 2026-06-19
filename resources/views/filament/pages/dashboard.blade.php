<x-filament-panels::page class="warehouse-dashboard-page">
    <x-filament-widgets::widgets
        :columns="1"
        :data="$this->getWidgetData()"
        :widgets="$this->getVisibleWidgets()"
    />

    <div class="warehouse-filter-shell">
        {{ $this->filtersForm }}
    </div>

    <x-filament-widgets::widgets
        :columns="1"
        :data="[
            'filters' => $this->filters,
            ...$this->getWidgetData(),
        ]"
        :widgets="$this->getVisibleOperationalWidgets()"
    />
</x-filament-panels::page>
