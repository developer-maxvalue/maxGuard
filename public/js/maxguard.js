(function () {
    'use strict';

    function setSidebar(open) {
        document.body.classList.toggle('mg-sidebar-open', open);
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-mg-sidebar-open]')) setSidebar(true);
        if (event.target.closest('[data-mg-sidebar-close]')) setSidebar(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setSidebar(false);
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            var search = document.querySelector('.mg-search input');
            if (search) search.focus();
        }
    });

    function initComplianceChart() {
        var element = document.getElementById('compliance-trend-chart');
        if (!element || typeof ApexCharts === 'undefined' || !window.MaxGuardPage) return;

        var chart = new ApexCharts(element, {
            series: [{ name: 'Compliance score', data: window.MaxGuardPage.trend }],
            chart: {
                type: 'area',
                height: 240,
                toolbar: { show: false },
                zoom: { enabled: false },
                fontFamily: 'Inter, sans-serif'
            },
            colors: ['#2563eb'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 3 },
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.22, opacityTo: 0.02, stops: [0, 95, 100] }
            },
            grid: { borderColor: '#e2e8f0', strokeDashArray: 0, padding: { left: 8, right: 8 } },
            xaxis: {
                categories: window.MaxGuardPage.trendLabels,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
            },
            yaxis: {
                min: 40,
                max: 100,
                tickAmount: 3,
                labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
            },
            tooltip: { theme: 'light', y: { formatter: function (value) { return value + '/100'; } } },
            markers: { size: 0, hover: { size: 5 } }
        });

        chart.render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initComplianceChart);
    } else {
        initComplianceChart();
    }
})();

