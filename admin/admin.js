document.addEventListener('DOMContentLoaded', function () {

    // Get the interface elements.
    const startButton = document.getElementById('alt-generator-start');
    const limitSelect = document.getElementById('alt-generator-limit');
    const delayInput = document.getElementById('alt-generator-delay');
    const countdownPanel = document.getElementById('alt-generator-countdown');
    const countdownTime = document.getElementById('alt-generator-countdown-time');
    const countdownBar = document.getElementById('alt-generator-countdown-bar');
    const countdownState = document.getElementById('alt-generator-countdown-state');
    const progressText = document.getElementById('alt-generator-progress');
    const pauseButton = document.getElementById('alt-generator-pause');
    const resumeButton = document.getElementById('alt-generator-resume');
    const cancelButton = document.getElementById('alt-generator-cancel');

    // Current list of images that still need alt text (not batch progress).
    const missingList = document.getElementById('alt-generator-missing-list');
    const missingCount = document.getElementById('alt-generator-missing-count');
    const previewLabel = document.getElementById('alt-generator-preview-label');
    const previewEmpty = document.getElementById('alt-generator-preview-empty');

    // Get the live image display elements.
    const failuresPanel = document.getElementById('alt-generator-failures');
    const failuresList = document.getElementById('alt-generator-failure-list');
    const resultPanel = document.getElementById('alt-generator-result');
    const resultStatus = document.getElementById('alt-generator-result-status');
    const resultDetails = document.getElementById('alt-generator-result-details');
    const resultImage = document.getElementById('alt-generator-result-image');
    const resultDescription = document.getElementById('alt-generator-result-description');

    // Create a button for recovering interrupted sessions.
    const recoverButton = document.createElement('button');

    recoverButton.type = 'button';
    recoverButton.textContent = 'Recover Session';
    recoverButton.className = 'button button-secondary';
    recoverButton.style.marginLeft = '10px';
    recoverButton.style.display = 'none';

    // Track whether processing has been paused.
    let pauseRequested = false;
    let cancelRequested = false;
    let loopActive = false;
    let countdownRemainingMs = 0;
    let countdownTotalMs = 0;
    let countdownActive = false;
    let countdownDeadline = 0;
    let countdownTimer = null;

    // Stop if the required controls are not present.
    if (!startButton || !limitSelect || !progressText || !pauseButton || !resumeButton || !cancelButton) {
        return;
    }

    // Position the recovery button beside Resume.
    resumeButton.insertAdjacentElement('afterend', recoverButton);

    // Store this browser's display preference without affecting server-side batches.
    const delayStorageKey = 'alt-generator-display-delay-seconds';

    function readDelaySeconds() {
        if (!delayInput) {
            return 3;
        }
        const raw = Number(delayInput.value);
        const seconds = Number.isFinite(raw) ? Math.min(60, Math.max(0, Math.round(raw))) : 3;
        delayInput.value = String(seconds);
        return seconds;
    }

    function formatCountdown(ms) {
        const totalSeconds = Math.max(0, Math.ceil(ms / 1000));
        return `${String(Math.floor(totalSeconds / 60)).padStart(2, '0')}:${String(totalSeconds % 60).padStart(2, '0')}`;
    }

    function renderCountdown(state) {
        if (!countdownPanel || !countdownTime || !countdownBar || !countdownState) {
            return;
        }
        countdownPanel.hidden = state === 'hidden';
        if (state === 'hidden') {
            return;
        }
        countdownTime.textContent = formatCountdown(countdownRemainingMs);
        const fraction = countdownTotalMs > 0
            ? Math.max(0, Math.min(1, countdownRemainingMs / countdownTotalMs))
            : 0;
        countdownBar.value = Math.round((1 - fraction) * 100);
        countdownState.textContent = state === 'paused'
            ? 'Paused — resume to continue the countdown.'
            : 'Next image starts when the countdown reaches zero.';
    }

    function clearCountdown() {
        if (countdownTimer !== null) {
            clearTimeout(countdownTimer);
            countdownTimer = null;
        }
        countdownActive = false;
        countdownRemainingMs = 0;
        countdownTotalMs = 0;
        renderCountdown('hidden');
    }

    // Saved per browser, but can also be edited during an active countdown.
    if (delayInput) {
        try {
            const stored = localStorage.getItem(delayStorageKey);
            if (stored !== null && stored.trim() !== '') {
                delayInput.value = stored;
            }
        } catch (error) {
            // Restricted storage must not block processing.
        }
        readDelaySeconds();
        delayInput.addEventListener('change', function () {
            const seconds = readDelaySeconds();
            try {
                localStorage.setItem(delayStorageKey, String(seconds));
            } catch (error) {
                // The setting still works without browser storage.
            }
            if (countdownActive) {
                // Preserve elapsed time when the administrator changes the duration.
                const elapsed = Math.max(0, countdownTotalMs -
                    Math.max(0, countdownDeadline - performance.now()));
                countdownTotalMs = seconds * 1000;
                countdownRemainingMs = Math.max(0, countdownTotalMs - elapsed);
                countdownDeadline = performance.now() + countdownRemainingMs;
                renderCountdown('running');
            } else if (pauseRequested && countdownRemainingMs > 0) {
                const elapsed = Math.max(0, countdownTotalMs - countdownRemainingMs);
                countdownTotalMs = seconds * 1000;
                countdownRemainingMs = Math.max(0, countdownTotalMs - elapsed);
                renderCountdown('paused');
            }
        });
    }

    // Awaited between images: the countdown can be paused, cancelled, or changed live.
    async function showResultForDelay(resumingCountdown = false) {
        if (!resumingCountdown) {
            countdownTotalMs = readDelaySeconds() * 1000;
            countdownRemainingMs = countdownTotalMs;
        }
        if (countdownRemainingMs <= 0 || cancelRequested) {
            clearCountdown();
            return;
        }

        countdownActive = true;
        countdownDeadline = performance.now() + countdownRemainingMs;
        renderCountdown('running');

        while (!pauseRequested && !cancelRequested) {
            countdownRemainingMs = Math.max(0, countdownDeadline - performance.now());
            renderCountdown('running');
            if (countdownRemainingMs <= 0) {
                break;
            }
            await new Promise((resolve) => {
                countdownTimer = setTimeout(resolve, Math.min(100, countdownRemainingMs));
            });
            countdownTimer = null;
        }

        countdownActive = false;
        if (cancelRequested || countdownRemainingMs <= 0) {
            clearCountdown();
        } else if (pauseRequested) {
            countdownRemainingMs = Math.max(0, countdownDeadline - performance.now());
            renderCountdown('paused');
        }
    }

    // Display the latest processed image and its generated description.
    function showProcessedImage(progress) {

        // Check that the display elements exist.
        if (
            !resultPanel ||
            !resultStatus ||
            !resultDetails ||
            !resultImage ||
            !resultDescription ||
            !progress.image_id
        ) {
            return;
        }

        // Make the result section visible.
        resultPanel.hidden = false;

        // Display the processing status.
        resultStatus.textContent = progress.error
            ? 'Failed to process image.'
            : 'Alt text saved successfully.';

        // Display the image ID and filename.
        resultDetails.textContent =
            `Image ID: ${progress.image_id}` +
            (progress.filename ? ` | Filename: ${progress.filename}` : '');

        // Display the generated description or error.
        resultDescription.textContent = progress.error
            ? `Error: ${progress.error}`
            : (progress.description || 'No description returned.');

        // Display the image preview.
        if (progress.preview_url) {

            resultImage.src = progress.preview_url;
            resultImage.hidden = false;

        } else {

            resultImage.removeAttribute('src');
            resultImage.hidden = true;

        }
    }

    // Request a pause after the current image finishes.
    pauseButton.addEventListener('click', function () {

        pauseRequested = true;

        // Prevent repeated clicks.
        pauseButton.disabled = true;

        // Inform the administrator.
        progressText.textContent = 'Pausing after the current image...';
        if (countdownActive) {
            countdownRemainingMs = Math.max(0, countdownDeadline - performance.now());
            renderCountdown('paused');
        }

    });

    // Send an AJAX request to WordPress.
    async function sendRequest(action, data = {}) {

        const formData = new FormData();

        formData.append('action', action);
        formData.append('nonce', altGeneratorBulk.nonce);

        for (const [key, value] of Object.entries(data)) {
            formData.append(key, value);
        }

        const response = await fetch(altGeneratorBulk.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.data?.message || 'Processing request failed.'
            );
        }

        return result.data;
    }

    // Count images missing alt text independently of the selected batch size.
    function renderFailures(failures) {
        if (!failuresPanel || !failuresList) return;
        failuresList.replaceChildren();
        for (const entry of (Array.isArray(failures) ? failures : [])) {
            const item = document.createElement('li');
            item.textContent = `Image ${entry.image_id}: ${entry.message || entry.code || 'Unknown error'}`;
            failuresList.appendChild(item);
        }
        failuresPanel.hidden = failuresList.children.length === 0;
    }

    function updateMissingDisplay() {
        if (!missingList || !missingCount || !previewLabel || !previewEmpty) {
            return;
        }

        const remaining = missingList.children.length;
        missingCount.textContent = String(remaining);
        previewLabel.hidden = remaining === 0;
        previewEmpty.hidden = remaining !== 0;
    }

    // Remove an entry only when the server confirms its alt text was saved.
    function removeSavedImage(progress) {
        if (!missingList || progress.error || !progress.image_id) {
            return;
        }

        const item = Array.from(missingList.children).find(
            (element) => Number(element.dataset.imageId) === Number(progress.image_id)
        );

        if (item) {
            item.remove();
            updateMissingDisplay();
        }
    }

    // Reconcile against WordPress after a refresh, interruption or other edits.
    async function refreshMissingImages() {
        if (!missingList) {
            return;
        }

        const response = await sendRequest('alt_generator_bulk_missing_images');
        missingList.replaceChildren();

        for (const image of response.images) {
            const item = document.createElement('li');
            item.dataset.imageId = String(image.id);
            item.textContent = `Image ID: ${image.id} — ${image.title}`;
            missingList.appendChild(item);
        }

        updateMissingDisplay();
    }

    // Refresh buttons based on the session stored in WordPress.
    async function refreshControls() {
        const session = await sendRequest('alt_generator_bulk_status');
        const unfinished = session.exists && session.processed < session.total;
        const active = unfinished && session.status === 'running';
        startButton.disabled = unfinished || !altGeneratorBulk.hasApiKey;
        limitSelect.disabled = unfinished;
        resumeButton.disabled = !(unfinished && session.status === 'paused');
        cancelButton.disabled = !unfinished;
        recoverButton.style.display = active ? 'inline-block' : 'none';
        recoverButton.disabled = false;
        return session;
    }

    // Cancel a stopped or interrupted batch; never delete already saved alt text.
    async function cancelBatch() {
        clearCountdown();
        try {
            const result = await sendRequest('alt_generator_bulk_cancel');
            progressText.textContent = result.message;
            renderFailures([]);
            await refreshControls();
            await refreshMissingImages().catch(console.error);
        } catch (error) {
            progressText.textContent = 'Cancellation failed: ' + error.message;
            await refreshControls().catch(() => {});
        }
    }

    cancelButton.addEventListener('click', async function () {
        if (!confirm('Cancel this batch? Alt text already saved will be kept.')) {
            return;
        }
        cancelRequested = true;
        clearCountdown();
        cancelButton.disabled = true;
        pauseButton.disabled = true;
        if (loopActive) {
            progressText.textContent = 'Cancelling after the current image finishes...';
            return;
        }
        await cancelBatch();
    });

    // Check for an existing processing session when the page loads.
    async function restoreSession() {

        try {

            // Retrieve the saved queue status from WordPress.
            const session = await sendRequest(
                'alt_generator_bulk_status'
            );

            // Refresh the preview using the current Media Library state.
            await refreshMissingImages().catch(console.error);

            // No previous processing session exists.
            if (!session.exists) {
                await refreshControls();
                return;
            }

            renderFailures(session.failures);

            // Display saved processing progress.
            progressText.textContent =
                `Processed ${session.processed} of ${session.total}. ` +
                `Successful: ${session.completed}. ` +
                `Failed: ${session.failed}.`;

            // Restore a paused session.
            if (session.status === 'paused') {

                resumeButton.disabled = false;
                startButton.disabled = true;
                limitSelect.disabled = true;
                cancelButton.disabled = false;

                progressText.textContent +=
                    ' Processing paused. Click Resume to continue.';

            } else if (session.status === 'completed') {

                progressText.textContent +=
                    ' Processing complete.';
                startButton.disabled = !altGeneratorBulk.hasApiKey;
                limitSelect.disabled = false;

            } else if (session.status === 'running') {

                // An unfinished session may have been interrupted.
                startButton.disabled = true;
                resumeButton.disabled = true;
                limitSelect.disabled = true;

                // Show the recovery option.
                recoverButton.style.display = 'inline-block';
                cancelButton.disabled = false;

                progressText.textContent +=
                    ' Processing session unfinished. Close any other processing tabs before recovering.';

            }

            await refreshControls();

        } catch (error) {

            progressText.textContent =
                'Could not restore processing status: ' + error.message;

        }
    }

    // Process images consecutively.
    // Set resume to true to continue an existing queue.
    async function processQueue(resume = false) {

        loopActive = true;
        cancelRequested = false;
        if (!resume) {
            clearCountdown();
        }
        try {

            // Disable controls while processing.
            startButton.disabled = true;
            resumeButton.disabled = true;
            limitSelect.disabled = true;

            // Reset the pause state.
            pauseRequested = false;
            cancelButton.disabled = false;

            progressText.textContent = resume
                ? 'Resuming processing...'
                : 'Preparing images...';

            if (!resume) renderFailures([]);

            // Start a new queue or resume the existing one.
            const start = resume
                ? await sendRequest('alt_generator_bulk_resume')
                : await sendRequest('alt_generator_bulk_start', {
                    limit: limitSelect.value
                });

            // Check whether any images need processing.
            if (start.total === 0) {

                progressText.textContent = 'No images need alt text.';
                return;

            }

            // Enable Pause once the queue is ready.
            pauseButton.disabled = false;

            let finished = false;

            // A pause during the countdown resumes the remaining display time.
            if (resume && countdownRemainingMs > 0) {
                await showResultForDelay(true);
            }

            // Keep processing until every image has been attempted.
            while (!finished && !pauseRequested && !cancelRequested) {

                // Process the next image.
                const progress = await sendRequest(
                    'alt_generator_bulk_step'
                );

                // Update processing progress.
                progressText.textContent =
                    `Processed ${progress.processed} of ${progress.total}. ` +
                    `Successful: ${progress.completed}. ` +
                    `Failed: ${progress.failed}.`;

                renderFailures(progress.failures);

                // Display the latest processed image and description.
                showProcessedImage(progress);
                removeSavedImage(progress);

                // Log individual image errors.
                if (progress.error) {

                    console.error(
                        `Image ${progress.image_id}: ${progress.error}`
                    );

                }

                // Check whether the queue is complete.
                finished = progress.done;

                // Keep the latest image/description visible before moving on.
                // The wait ends immediately when Pause or Cancel is requested.
                if (!finished && !pauseRequested && !cancelRequested) {
                    await showResultForDelay();
                }
            }

            // Check whether processing was paused.
            if (cancelRequested && !finished) {
                await cancelBatch();
            } else if (pauseRequested && !finished) {

                // The current image has finished processing.
                // Tell WordPress to save the paused state.
                const pauseResult = await sendRequest(
                    'alt_generator_bulk_pause'
                );

                progressText.textContent +=
                    ' ' + pauseResult.message;

                // Allow the administrator to resume processing.
                resumeButton.disabled = false;

            } else if (!pauseRequested && !cancelRequested) {

                // All selected images have been processed.
                clearCountdown();
                progressText.textContent += ' Processing complete.';

            }

        } catch (error) {

            // Display processing errors.
            progressText.textContent =
                'Processing stopped: ' + error.message;

        } finally {
            loopActive = false;
            if (cancelRequested) {
                // Also allow cancelling if the API request stopped with an error.
                const existing = await sendRequest('alt_generator_bulk_status').catch(() => null);
                if (existing && existing.exists && existing.processed < existing.total) {
                    await cancelBatch();
                }
            }

            // An interrupted request shouldn't leave a misleading active timer.
            if (!pauseRequested && !cancelRequested) {
                clearCountdown();
            }

            // Recheck the library after errors, cancellations and finished batches.
            await refreshMissingImages().catch(console.error);

            // Disable Pause because the processing loop has stopped.
            pauseButton.disabled = true;

            // Check the actual queue status saved in WordPress.
            try {

                // Restore controls for running, paused, completed, or cancelled queues.
                await refreshControls();

            } catch (statusError) {

                // Keep controls disabled if the queue status is unknown.
                startButton.disabled = true;
                resumeButton.disabled = true;
                limitSelect.disabled = true;

                console.error(
                    'Could not check processing status:',
                    statusError
                );

            }

        }

    } // End of processQueue()


    // Start a new processing session.
    startButton.addEventListener('click', function () {

        processQueue(false);

    });

    // Continue an existing processing session.
    resumeButton.addEventListener('click', function () {

        processQueue(true);

    });

    // Recover an interrupted processing session.
    recoverButton.addEventListener('click', async function () {

        // Avoid recovering a queue still running in another tab.
        const confirmed = confirm(
            'Have you closed all other tabs running the Alt Text Generator? ' +
            'Only recover a session that has stopped processing.'
        );

        if (!confirmed) {
            return;
        }

        recoverButton.disabled = true;

        progressText.textContent = 'Checking interrupted session...';

        try {

            // Ask WordPress to recover the saved queue.
            const result = await sendRequest(
                'alt_generator_bulk_recover'
            );

            // Hide Recover and enable Resume.
            recoverButton.style.display = 'none';
            resumeButton.disabled = false;
            cancelButton.disabled = false;

            progressText.textContent =
                `Recovered ${result.processed} of ${result.total} images. ` +
                'Click Resume to continue.';

        } catch (error) {

            progressText.textContent =
                'Recovery failed: ' + error.message;

            recoverButton.disabled = false;

        }

    });

    // Restore saved processing progress when WordPress loads.
    restoreSession();

}); // End of DOMContentLoaded