document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('statusChart').getContext('2d');

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: chartLabels,
            datasets: [{
                data: chartValues,
                backgroundColor: [
                    'rgba(207, 102, 121, 0.4)',
                    'rgba(187, 134, 252, 0.4)',
                    'rgba(3, 218, 198, 0.4)'
                ],
                borderColor: [
                    '#cf6679',
                    '#bb86fc',
                    '#03dac6'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = totalVolumes;
                            const percentage = Math.round((value / total) * 100);
                            return `${label}: ${value} tomes (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
});