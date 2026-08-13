</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const input = document.getElementById('topbarSearchInput');
    const form = document.getElementById('topbarSearchForm');
    if (!input || !form) return;

    // Find the first data table inside the page content
    const table = document.querySelector('.content table');

    if (table) {
        // We have a table on this page — filter it live instead of navigating away
        const rows = Array.from(table.querySelectorAll('tr')).filter(row => !row.querySelector('th'));

        input.addEventListener('keyup', function () {
            const query = input.value.trim().toLowerCase();
            rows.forEach(function (row) {
                const text = row.textContent.toLowerCase();
                row.style.display = (query === '' || text.includes(query)) ? '' : 'none';
            });
        });

        // Stop Enter/submit from navigating to the global search page on table pages
        form.addEventListener('submit', function (e) {
            e.preventDefault();
        });
    }
    // If there's no table on the page (e.g. Dashboard), the form submits normally to search/index.php
})();
</script>

</body>
</html>