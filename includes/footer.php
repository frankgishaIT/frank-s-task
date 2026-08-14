</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const input = document.getElementById('topbarSearchInput');
    const form = document.getElementById('topbarSearchForm');
    if (!input || !form) return;

    // Always let the form submit normally to search/index.php, which queries
    // the full database (not just what's loaded on the current page/table).
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            form.submit();
        }
    });
})();
</script>

</body>
</html>