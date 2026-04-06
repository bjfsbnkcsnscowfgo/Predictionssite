
</main>
<!-- End Main Content -->

<!-- Footer -->
<footer class="site-footer text-center py-4 mt-auto">
    <div class="container-xl">
        <p class="text-secondary mb-1">
            <i class="fas fa-chart-line me-1"></i> <?= sanitize(SITE_NAME) ?>
        </p>
        <p class="text-secondary small mb-0">
            &copy; <?= date('Y') ?> <?= sanitize(SITE_NAME) ?>. All rights reserved.
        </p>
    </div>
</footer>

<!-- Bootstrap 5.3.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

<!-- Custom JavaScript -->
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>

</body>
</html>
