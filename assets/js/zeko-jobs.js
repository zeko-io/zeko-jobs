jQuery(document).ready(function($) {

    /* =============================================
       Accessibility — Live Region Announcements
       ============================================= */
    function zekoAnnounce(message) {
        var $live = $("#zeko-aria-live");
        if ($live.length) {
            $live.text("");
            setTimeout(function() { $live.text(message); }, 50);
        }
    }

    /* =============================================
       Filter Persistence (localStorage)
       ============================================= */

    var $filterForm = $(".zeko-jobs-filter");
    if ($filterForm.length) {
        var savedFilters = localStorage.getItem("zeko-jobs-filters");
        if (savedFilters) {
            try {
                savedFilters = JSON.parse(savedFilters);
                var now = Date.now();
                if (savedFilters.expires && savedFilters.expires > now) {
                    $.each(savedFilters.data, function(key, val) {
                        var $el = $filterForm.find('[name="' + key + '"]');
                        if ($el.length) {
                            if ($el.is("select")) {
                                $el.val(val);
                            } else {
                                $el.val(val);
                            }
                        }
                    });
                } else {
                    localStorage.removeItem("zeko-jobs-filters");
                }
            } catch(e) {}
        }

        $filterForm.on("submit", function() {
            var data = {};
            $filterForm.serializeArray().forEach(function(field) {
                if (field.value) {
                    data[field.name] = field.value;
                }
            });
            localStorage.setItem("zeko-jobs-filters", JSON.stringify({
                data: data,
                expires: Date.now() + (7 * 24 * 60 * 60 * 1000)
            }));
        });

        $filterForm.find(".zeko-clear-filters").on("click", function() {
            localStorage.removeItem("zeko-jobs-filters");
        });
    }

    function zekoShowMessage($container, message, type) {
        var cls = type === 'success' ? 'zeko-inline-success' : 'zeko-inline-error';
        var color = type === 'success' ? 'var(--color-secondary)' : 'var(--color-accent)';
        $container.html('<p class="' + cls + '" style="color:' + color + ';font-size:14px;margin-top:8px;">' + escapeHtml(message) + '</p>');
        setTimeout(function() { $container.html(''); }, 5000);
    }

    function zekoI18n() {
        return (typeof zeko_jobs_ajax !== 'undefined' && zeko_jobs_ajax.i18n) ? zeko_jobs_ajax.i18n : {};
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function sanitizeHtml(html) {
        if (!html) return '';
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        tmp.querySelectorAll('script, iframe, object, embed, form, input, textarea, select, style, link').forEach(function(el) { el.remove(); });
        tmp.querySelectorAll('*').forEach(function(el) {
            Array.from(el.attributes).forEach(function(attr) {
                if (attr.name.startsWith('on') || attr.value.trim().toLowerCase().startsWith('javascript:')) {
                    el.removeAttribute(attr.name);
                }
            });
        });
        return tmp.innerHTML;
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            var temp = document.createElement('textarea');
            temp.value = text;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
        }
    }

    /* =============================================
       Single Job — Copy Link
       ============================================= */
    $(document).on("click", ".zeko-copy-link-btn", function() {
        var $btn = $(this);
        var url = $btn.data("url");
        if (!url) return;
        copyToClipboard(url);
        var originalText = $btn.html();
        $btn.addClass("is-copied");
        $btn.html('<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' + (typeof zeko_jobs_i18n !== 'undefined' && zeko_jobs_i18n.copied ? zeko_jobs_i18n.copied : 'Copied!'));
        setTimeout(function() {
            $btn.html(originalText);
            $btn.removeClass("is-copied");
        }, 2000);
    });

    /* =============================================
    $(".zeko-bookmark-btn").on("click", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");

        if (typeof zeko_jobs_ajax === 'undefined') return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_bookmark",
                nonce: nonce,
                job_id: jobId
            },
            beforeSend: function() {
                $btn.prop("disabled", true).text("Saving...");
            },
            success: function(response) {
                if (response.success) {
                    $btn.toggleClass("bookmarked");
                    var isBookmarked = $btn.hasClass("bookmarked");
                    $btn.text(isBookmarked ? "Bookmarked" : "Bookmark Job");
                    $btn.attr("aria-pressed", isBookmarked);
                    zekoAnnounce(isBookmarked ? "Job bookmarked." : "Bookmark removed.");
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not save job.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            },
            complete: function() {
                $btn.prop("disabled", false);
            }
        });
    });

    /* =============================================
       File Upload — Custom area click, preview, drag-drop
       ============================================= */
    var _zekoFileUploadLock = false;
    $(document).on("click", ".zeko-file-upload", function(e) {
        if (_zekoFileUploadLock) return;
        e.preventDefault();
        _zekoFileUploadLock = true;
        var input = this.querySelector(".zeko-file-input");
        if (input) input.click();
        _zekoFileUploadLock = false;
    });

    $(document).on("change", ".zeko-file-input", function() {
        var $upload = $(this).closest(".zeko-file-upload");
        var $preview = $upload.siblings(".zeko-file-preview");
        var file = this.files[0];

        if (!file) return;

        var maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            alert("File is too large. Maximum size is 5MB.");
            $(this).val("");
            return;
        }

        var allowed = ["application/pdf", "application/msword", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"];
        if (allowed.indexOf(file.type) === -1) {
            alert("Invalid file type. Only PDF, DOC, and DOCX are allowed.");
            $(this).val("");
            return;
        }

        $preview.find(".zeko-file-name").text(file.name);
        var sizeMB = (file.size / (1024 * 1024)).toFixed(1);
        $preview.find(".zeko-file-size").text(sizeMB + " MB");
        $preview.show();
        $upload.hide();
    });

    $(document).on("click", ".zeko-file-remove", function(e) {
        e.stopPropagation();
        var $preview = $(this).closest(".zeko-file-preview");
        var $upload = $preview.siblings(".zeko-file-upload");
        $preview.hide();
        $upload.show();
        $upload.find(".zeko-file-input").val("");
    });

    $(document).on("dragover", ".zeko-file-upload", function(e) {
        e.preventDefault();
        $(this).addClass("zeko-file-dragover");
    });

    $(document).on("dragleave drop", ".zeko-file-upload", function(e) {
        e.preventDefault();
        $(this).removeClass("zeko-file-dragover");
    });

    $(document).on("drop", ".zeko-file-upload", function(e) {
        e.preventDefault();
        var files = e.originalEvent.dataTransfer.files;
        if (files.length) {
            var $input = $(this).find(".zeko-file-input");
            $input[0].files = files;
            $input.trigger("change");
        }
    });

    /* =============================================
       Dashboard — Status badge click (seeker timeline)
       ============================================= */
    $(document).on("click", ".zeko-send-digest-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var nonce = $btn.data("nonce");

        if (typeof zeko_jobs_ajax === 'undefined') return;

        $btn.prop("disabled", true).text("Sending...");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_send_digest",
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    zekoShowMessage($btn.closest(".zeko-dashboard-section"), response.data.message || "Digest sent.", "success");
                } else {
                    zekoShowMessage($btn.closest(".zeko-dashboard-section"), response.data.message || "No new matching jobs found.", "error");
                }
            },
            error: function() {
                zekoShowMessage($btn.closest(".zeko-dashboard-section"), zekoI18n().networkError || "Network error.", "error");
            },
            complete: function() {
                $btn.prop("disabled", false).text("Send Me Email Digest");
            }
        });
    });

    /* =============================================
       Dashboard — Status badge click (seeker timeline)
       ============================================= */
    $(".zeko-message-employer-btn").on("click", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var employerId = $btn.data("employer-id");
        var jobTitle = $btn.data("job-title");
        var nonce = $btn.data("nonce");

        if (typeof zeko_jobs_ajax === 'undefined') return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_message_employer",
                nonce: nonce,
                employer_id: employerId,
                job_title: jobTitle
            },
            beforeSend: function() {
                $btn.prop("disabled", true).text("Sending...");
            },
            success: function(response) {
                if (response.success) {
                    zekoShowMessage($("body"), zekoI18n().messageSent || "Message sent to employer.", "success");
                    $btn.text("Message Sent").addClass("button-secondary").prop("disabled", true);
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not send message.", "error");
                    $btn.prop("disabled", false).text("Message Employer");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
                $btn.prop("disabled", false).text("Message Employer");
            }
        });
    });

    /* =============================================
       Archive — Apply (expand form)
       ============================================= */
    $(".zeko-job-apply-btn").on("click", function(e) {
        e.preventDefault();
        var jobId = $(this).data("job-id");
        var $wrapper = $("#zeko-job-apply-form-wrapper-" + jobId);
        $wrapper.slideToggle(200);
        $(this).toggleClass("active");
    });

    $(document).on("change", ".zeko-template-select", function() {
        var $select = $(this);
        var targetId = $select.data("target");
        var $textarea = $("#" + targetId);
        var selectedVal = $select.val();
        if (selectedVal && typeof zeko_jobs_ajax !== "undefined" && zeko_jobs_ajax.coverTemplates) {
            var tpl = zeko_jobs_ajax.coverTemplates[selectedVal];
            if (tpl && tpl.content) {
                $textarea.val(tpl.content);
            }
        }
    });

    /* =============================================
       Easy Apply — Resume mode toggle
       ============================================= */
    $(document).on("change", ".zeko-resume-mode", function() {
        var $wrapper = $(this).closest(".zeko-field");
        if ($(this).val() === "new") {
            $wrapper.find(".zeko-resume-file-input").show().prop("required", true);
        } else {
            $wrapper.find(".zeko-resume-file-input").hide().prop("required", false).val("");
        }
    });

    /* Auto-select first cover template when apply form opens */
    $(document).on("click", ".zeko-job-apply-btn", function() {
        var $card = $(this).closest("article");
        var $wrapper = $card.find(".zeko-job-apply-form-wrapper");
        var $select = $wrapper.find(".zeko-template-select");
        if ($select.length && !$select.val()) {
            var $firstOption = $select.find("option:not(:first):eq(0)");
            if ($firstOption.length) {
                $select.val($firstOption.val()).trigger("change");
            }
        }
    });

    /* =============================================
       Archive — Apply form AJAX submission
       ============================================= */
    $(document).on("submit", ".zeko-job-apply-form", function(e) {
        e.preventDefault();
        var $form = $(this);
        var $wrapper = $form.closest(".zeko-job-apply-form-wrapper");
        var $message = $form.find(".zeko-application-message");

        if (typeof zeko_jobs_ajax === 'undefined') return;

        var formData = new FormData($form[0]);
        formData.append("action", "zeko_job_apply");

        var $submitBtn = $form.find("button[type='submit']");
        $submitBtn.prop("disabled", true).text("Submitting...");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    zekoShowMessage($message, zekoI18n().applySuccess || "Application submitted.", "success");
                    zekoAnnounce("Application submitted successfully.");
                    $form.find("textarea, input[type='file']").val("");
                    setTimeout(function() { $wrapper.slideUp(200); }, 2000);
                } else {
                    zekoShowMessage($message, response.data.message || zekoI18n().applyError || "Application failed.", "error");
                }
            },
            error: function() {
                zekoShowMessage($message, zekoI18n().networkError || "Network error.", "error");
            },
            complete: function() {
                $submitBtn.prop("disabled", false).text("Submit Application");
            }
        });
    });

    /* =============================================
       Single Job — Deadline countdown
       ============================================= */
    function zekoUpdateCountdowns() {
        var now = new Date().getTime();
        $(".zeko-deadline-countdown").each(function() {
            var $el = $(this);
            var deadline = $el.data("deadline");
            if (!deadline) return;
            var target = new Date(deadline + "T00:00:00").getTime();
            var diff = target - now;
            if (diff <= 0) {
                $el.find(".zeko-countdown-text").text("Closed");
                $el.addClass("zeko-deadline-soon");
                return;
            }
            var days = Math.floor(diff / (1000 * 60 * 60 * 24));
            var hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var text = "(" + days + "d " + hours + "h left)";
            $el.find(".zeko-countdown-text").text(text);
            if (days <= 3) {
                $el.addClass("zeko-deadline-soon");
            }
        });
    }
    zekoUpdateCountdowns();
    setInterval(zekoUpdateCountdowns, 60000);

    /* =============================================
       Single Job — Easy Apply
       ============================================= */
    $(document).on("click", ".zeko-easy-apply-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");

        if (typeof zeko_jobs_ajax === 'undefined') return;

        $btn.prop("disabled", true).text("Applying...");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_easy_apply",
                nonce: nonce,
                job_id: jobId
            },
            success: function(response) {
                if (response.success) {
                    zekoShowMessage($("body"), zekoI18n().applySuccess || "Application submitted.", "success");
                    zekoAnnounce("Application submitted successfully.");
                    $btn.text("Applied").addClass("applied").prop("disabled", true);
                } else {
                    zekoShowMessage($("body"), response.data.message || "Easy Apply failed.", "error");
                    $btn.prop("disabled", false).text("Easy Apply");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
                $btn.prop("disabled", false).text("Easy Apply");
            }
        });
    });

    /* =============================================
       Single Job — Not Interested
       ============================================= */
    $(document).on("click", ".zeko-not-interested-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");

        if (!confirm("Mark this job as not interested?")) return;
        if (typeof zeko_jobs_ajax === 'undefined') return;

        $btn.prop("disabled", true).text("Feedback sent");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_not_interested",
                nonce: nonce,
                job_id: jobId
            },
            success: function(response) {
                if (!response.success) {
                    zekoShowMessage($("body"), response.data.message || "Could not send feedback.", "error");
                    $btn.prop("disabled", false).text("Not Interested");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
                $btn.prop("disabled", false).text("Not Interested");
            }
        });
    });

    /* =============================================
       Single Job — Report / Flag
       ============================================= */
    $(document).on("click", ".zeko-report-job-btn", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;
        $("#zeko-report-modal").prop("hidden", false).attr("aria-hidden", "false");
        $("body").css("overflow", "hidden");
    });

    function zekoCloseReportModal() {
        $("#zeko-report-modal").prop("hidden", true).attr("aria-hidden", "true");
        $("body").css("overflow", "");
        $("#zeko-report-details").val("");
        $("#zeko-report-reason").val("inappropriate");
        $(".zeko-report-modal-message").text("").removeClass("error success");
        $(".zeko-report-submit-btn").prop("disabled", false);
    }

    $(document).on("click", ".zeko-report-modal-close, .zeko-report-modal-cancel", function(e) {
        e.preventDefault();
        zekoCloseReportModal();
    });

    $(document).on("click", "#zeko-report-modal", function(e) {
        if (e.target === this) zekoCloseReportModal();
    });

    $(document).on("keydown", function(e) {
        if (e.key === "Escape") zekoCloseReportModal();
    });

    $(document).on("click", ".zeko-report-submit-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");
        var reason = $("#zeko-report-reason").val();
        var details = $("#zeko-report-details").val();
        var $msg = $(".zeko-report-modal-message");

        $btn.prop("disabled", true);

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_flag",
                nonce: nonce,
                job_id: jobId,
                reason: reason,
                details: details
            },
            success: function(response) {
                if (response.success) {
                    $msg.text(response.data.message).addClass("success");
                    setTimeout(zekoCloseReportModal, 1800);
                } else {
                    $msg.text(response.data.message || "Could not submit report.").addClass("error");
                    $btn.prop("disabled", false);
                }
            },
            error: function() {
                $msg.text(zekoI18n().networkError || "Network error.").addClass("error");
                $btn.prop("disabled", false);
            }
        });
    });

    /* =============================================
       Company profile — Follow / Unfollow
       ============================================= */
    $(document).on("click", ".zeko-follow-btn", function(e) {
        if (e.target.classList.contains("zeko-follow-login")) return;
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;

        var $btn = $(this);
        var employerId = $btn.data("company");
        var nonce = $btn.data("nonce");
        var wasFollowing = $btn.hasClass("is-following");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_follow_company",
                nonce: nonce,
                employer_id: employerId,
                action_type: wasFollowing ? "unfollow" : "follow"
            },
            success: function(response) {
                if (response.success) {
                    $btn.toggleClass("is-following", response.data.is_following);
                    $btn.text(response.data.is_following ? zekoI18n().following || "Following" : zekoI18n().follow || "Follow");
                    var $count = $(".zeko-company-followers");
                    if ($count.length) {
                        $count.text((response.data.follower_count || 0) + " " + (response.data.follower_count === 1 ? zekoI18n().follower || "follower" : zekoI18n().followers || "followers"));
                    }
                }
            },
            error: function() { }
        });
    });

    /* =============================================
       Employer dashboard — Resume search
       ============================================= */
    $(document).on("submit", "#zeko-resume-search-form", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;

        var $form = $(this);
        var $results = $("#zeko-resume-search-results");
        var $msg = $("#zeko-resume-search-message");
        $results.html('<p class="zeko-empty-state">' + (zekoI18n().searching || "Searching...") + '</p>');
        $msg.text("").removeClass("error success");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: $form.serialize() + "&action=zeko_job_search_resumes",
            success: function(response) {
                if (!response.success) {
                    $msg.text(response.data.message || "Search failed.").addClass("error");
                    $results.empty();
                    return;
                }
                var candidates = response.data.candidates || [];
                if (!candidates.length) {
                    $results.html('<p class="zeko-empty-state">' + (zekoI18n().noCandidates || "No matching candidates found.") + '</p>');
                    return;
                }
                var html = '';
                candidates.forEach(function(c) {
                    html += '<div class="zeko-resume-card">';
                    if (c.avatar) html += '<img src="' + c.avatar + '" alt="" class="zeko-resume-avatar" loading="lazy">';
                    html += '<div class="zeko-resume-card-info">';
                    html += '<h4><a href="' + c.profile_url + '" target="_blank" rel="noopener">' + $("<div>").text(c.name).html() + '</a></h4>';
                    if (c.location) html += '<p class="zeko-resume-location">' + $("<div>").text(c.location).html() + '</p>';
                    if (c.skills && c.skills.length) html += '<p class="zeko-resume-skills">' + c.skills.map(function(s) { return '<span>' + $("<div>").text(s).html() + '</span>'; }).join('') + '</p>';
                    if (c.bio) html += '<p class="zeko-resume-bio">' + $("<div>").text(c.bio).html() + '</p>';
                    html += '</div>';
                    html += '<div class="zeko-resume-card-actions">';
                    if (c.resume_url) html += '<a class="button button-secondary" href="' + c.resume_url + '" target="_blank" rel="noopener">' + (zekoI18n().viewResume || "View Resume") + '</a>';
                    html += '</div>';
                    html += '</div>';
                });
                $results.html(html);
            },
            error: function() {
                $msg.text(zekoI18n().networkError || "Network error.").addClass("error");
                $results.empty();
            }
        });
    });

    /* =============================================
       Dashboard — Application status update
       ============================================= */
    $(document).on("change", ".zeko-status-select", function() {
        var $select = $(this);
        var appId = $select.data("application-id");
        var nonce = $select.data("nonce");
        var newStatus = $select.val();

        if (typeof zeko_jobs_ajax === 'undefined') return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_update_status",
                nonce: nonce,
                application_id: appId,
                status: newStatus
            },
            success: function(response) {
                if (!response.success) {
                    zekoShowMessage($("body"), response.data.message || "Failed to update status.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    /* =============================================
       Dashboard — Withdraw application
       ============================================= */
    $(document).on("click", ".zeko-withdraw-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var appId = $btn.data("application-id");
        var nonce = $btn.data("nonce");

        if (typeof zeko_jobs_ajax === 'undefined') return;

        var $card = $btn.closest(".zeko-application-card");
        var jobTitle = $card.find("h4 a").text() || "this application";

        var $modal = $(
            '<div class="zeko-modal" style="display:flex;" role="dialog" aria-label="Withdraw Application">' +
                '<div class="zeko-modal-overlay"></div>' +
                '<div class="zeko-modal-dialog" style="max-width:420px;">' +
                    '<div class="zeko-modal-header">' +
                        '<h3>' + (typeof zeko_jobs_ajax !== "undefined" && zeko_jobs_ajax.i18n ? zeko_jobs_ajax.i18n.withdrawTitle || "Withdraw Application" : "Withdraw Application") + '</h3>' +
                        '<button type="button" class="zeko-modal-close" aria-label="Close">&times;</button>' +
                    '</div>' +
                    '<div class="zeko-modal-body">' +
                        '<p>' + (typeof zeko_jobs_ajax !== "undefined" && zeko_jobs_ajax.i18n ? zeko_jobs_ajax.i18n.withdrawConfirm || "Are you sure you want to withdraw your application for" : "Are you sure you want to withdraw your application for") + ' <strong>' + $("<span>").text(jobTitle).html() + '</strong>? ' +
                        (typeof zeko_jobs_ajax !== "undefined" && zeko_jobs_ajax.i18n ? zeko_jobs_ajax.i18n.withdrawWarning || "This cannot be undone." : "This cannot be undone.") + '</p>' +
                        '<div class="zeko-modal-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">' +
                            '<button type="button" class="button zeko-modal-close">' + (typeof zeko_jobs_ajax !== "undefined" && zeko_jobs_ajax.i18n ? zeko_jobs_ajax.i18n.cancel || "Cancel" : "Cancel") + '</button>' +
                            '<button type="button" class="button button-primary zeko-confirm-withdraw" style="background:#dc3545;border-color:#dc3545;">' + (typeof zeko_jobs_ajax !== "undefined" && zeko_jobs_ajax.i18n ? zeko_jobs_ajax.i18n.withdrawBtn || "Yes, Withdraw" : "Yes, Withdraw") + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        $("body").append($modal);

        $modal.on("click", ".zeko-modal-close, .zeko-modal-overlay", function() {
            $modal.remove();
        });

        $modal.on("click", ".zeko-confirm-withdraw", function() {
            $modal.remove();

            $.ajax({
                url: zeko_jobs_ajax.ajax_url,
                type: "POST",
                data: {
                    action: "zeko_job_withdraw",
                    nonce: nonce,
                    application_id: appId
                },
                beforeSend: function() {
                    $btn.prop("disabled", true).text("Withdrawing...");
                },
                success: function(response) {
                    if (response.success) {
                        $card.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        zekoShowMessage($("body"), response.data.message || "Could not withdraw.", "error");
                        $btn.prop("disabled", false).text("Withdraw");
                    }
                },
                error: function() {
                    zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
                    $btn.prop("disabled", false).text("Withdraw");
                }
            });
        });
    });

    /* =============================================
       Dashboard — Featured toggle
       ============================================= */
    $(document).on("click", ".zeko-featured-toggle-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");

        if (typeof zeko_jobs_ajax === 'undefined') return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_toggle_featured",
                nonce: nonce,
                job_id: jobId
            },
            beforeSend: function() {
                $btn.prop("disabled", true).text("Updating...");
            },
            success: function(response) {
                if (response.success) {
                    $btn.toggleClass("button-primary");
                    $btn.text($btn.hasClass("button-primary") ? "Unfeature" : "Feature");
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not update featured status.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            },
            complete: function() {
                $btn.prop("disabled", false);
            }
        });
    });

    /* =============================================
       Dashboard — Range slider (archive filters)
       ============================================= */
    $(".zeko-range-slider").each(function() {
        var $slider = $(this);
        var $minInput = $slider.find(".zeko-range-min");
        var $maxInput = $slider.find(".zeko-range-max");
        var min = parseInt($minInput.attr("min")) || 0;
        var max = parseInt($minInput.attr("max")) || 200000;
        var step = parseInt($minInput.attr("step")) || 1000;

        function updateSlider() {
            var minVal = parseInt($minInput.val()) || min;
            var maxVal = parseInt($maxInput.val()) || max;
            if (minVal > maxVal) {
                maxVal = minVal;
                $maxInput.val(maxVal);
            }
            $slider.find(".zeko-range-track").css({
                left: ((minVal - min) / (max - min)) * 100 + "%",
                width: ((maxVal - minVal) / (max - min)) * 100 + "%"
            });
        }

        $minInput.on("input", updateSlider);
        $maxInput.on("input", updateSlider);
        updateSlider();
    });

    /* =============================================
       Dashboard — Add note
       ============================================= */
    $(document).on("click", ".zeko-add-note-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var appId = $btn.data("application-id");
        var nonce = $btn.data("nonce");
        var $textarea = $("#zeko-note-text-" + appId);
        var noteText = $textarea.val();

        if (!noteText.trim()) return;
        if (typeof zeko_jobs_ajax === 'undefined') return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_add_note",
                nonce: nonce,
                application_id: appId,
                note: noteText
            },
            beforeSend: function() {
                $btn.prop("disabled", true).text("Adding...");
            },
            success: function(response) {
                if (response.success) {
                    $textarea.val("");
                    zekoShowMessage($("body"), zekoI18n().noteAdded || "Note added.", "success");
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not add note.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            },
            complete: function() {
                $btn.prop("disabled", false).text("Add Note");
            }
        });
    });

    /* =============================================
       Dashboard — Status badge click (seeker timeline)
       ============================================= */
    $(document).on("click", ".zeko-status-badge", function() {
        var $badge = $(this);
        var appId = $badge.data("application-id");
        var nonce = $badge.data("nonce");
        var $history = $("#zeko-history-" + appId);

        if ($history.length && $history.is(":visible")) {
            $history.slideUp();
            return;
        }

        if (typeof zeko_jobs_ajax === 'undefined') return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "GET",
            data: {
                action: "zeko_job_get_history",
                nonce: nonce,
                application_id: appId
            },
            success: function(response) {
                if (response.success && response.data.history) {
                    var html = "<ul>";
                    $.each(response.data.history, function(i, item) {
                        html += "<li><strong>" + escapeHtml(item.status) + "</strong> — " + escapeHtml(item.created_at) + "</li>";
                    });
                    html += "</ul>";
                    $history.html(html).slideDown();
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    /* =============================================
       Dashboard — Delete reminder
       ============================================= */
    $(document).on("click", ".zeko-delete-reminder-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var reminderId = $btn.data("reminder-id");
        var nonce = $btn.data("nonce");

        if (!confirm("Delete this reminder?")) return;
        if (typeof zeko_jobs_ajax === 'undefined') return;

        var $card = $btn.closest(".zeko-reminder-card");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_delete_reminder",
                nonce: nonce,
                reminder_id: reminderId
            },
            success: function(response) {
                if (response.success) {
                    $card.remove();
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not delete reminder.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), "Network error.", "error");
            }
        });
    });

    /* =============================================
       Dashboard — Dark mode toggle
       ============================================= */
    $(document).on("click", "#zeko-notif-bell-btn", function(e) {
        e.stopPropagation();
        var $dropdown = $("#zeko-notif-dropdown");
        $dropdown.toggle();
    });

    $(document).on("click", function(e) {
        var $dropdown = $("#zeko-notif-dropdown");
        if ($dropdown.length && !$(e.target).closest(".zeko-notif-bell").length) {
            $dropdown.hide();
        }
    });

    $(document).on("click", ".zeko-notif-mark-all-read", function() {
        var nonce = $(this).data("nonce");
        $.post(zeko_jobs_ajax.ajax_url, { action: "zeko_job_notif_read_all", nonce: nonce }, function() {
            $(".zeko-notif-item").removeClass("unread").addClass("read");
            $(".zeko-notif-badge").remove();
        });
    });

    $(document).on("click", ".zeko-notif-item", function() {
        var $item = $(this);
        var notifId = $item.data("notif-id");
        if (!notifId) return;
        var nonce = $item.closest(".zeko-notif-dropdown").find(".zeko-notif-mark-all-read").data("nonce");
        $.post(zeko_jobs_ajax.ajax_url, { action: "zeko_job_mark_notification_read", nonce: nonce, notification_id: notifId }, function() {
            $item.removeClass("unread").addClass("read");
        });
    });

    // Dark mode is now handled by zeko-core's ZekoDarkMode API
    // Legacy localStorage key migration
    if (localStorage.getItem("zeko-jobs-dark-mode") === "1") {
        localStorage.removeItem("zeko-jobs-dark-mode");
        if (window.ZekoDarkMode) { ZekoDarkMode.setTheme("dark"); }
    }

    /* =============================================
       Dashboard — Keyboard navigation
       ============================================= */
    $(document).on("keydown", ".zeko-jobs-dashboard", function(e) {
        if (e.key === "/" && !e.ctrlKey && !e.metaKey) {
            var $search = $("#zeko-dashboard-search");
            if ($search.length && document.activeElement !== $search[0]) {
                e.preventDefault();
                $search.focus();
            }
        }
    });

    /* =============================================
       Dashboard — Employer: update job status
       ============================================= */
    $(document).on("change", ".zeko-job-status-select", function() {
        var $select = $(this);
        var jobId = $select.data("job-id");
        var nonce = $select.data("nonce");
        var newStatus = $select.val();

        if (typeof zeko_jobs_ajax === 'undefined') return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_update_status",
                nonce: nonce,
                job_id: jobId,
                status: newStatus
            },
            success: function(response) {
                if (!response.success) {
                    zekoShowMessage($("body"), response.data.message || "Failed to update status.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    /* =============================================
       Dashboard — Employer: bulk actions
       ============================================= */
    $(document).on("change", "#zeko-bulk-action-select", function() {
        var action = $(this).val();
        if (!action) return;

        var $checked = $(".zeko-job-checkbox:checked");
        if ($checked.length === 0) {
            zekoShowMessage($("body"), "No jobs selected.", "error");
            $(this).val("");
            return;
        }

        if (!confirm("Apply '" + action + "' to " + $checked.length + " job(s)?")) {
            $(this).val("");
            return;
        }

        var jobIds = [];
        $checked.each(function() {
            jobIds.push($(this).data("job-id"));
        });

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_bulk_action",
                nonce: zeko_jobs_ajax.dashboard_nonce,
                job_ids: jobIds,
                bulk_action: action
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    zekoShowMessage($("body"), response.data.message || "Bulk action failed.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    /* =============================================
       Archive — Save Search
       ============================================= */
    $(document).on("click", ".zeko-save-search-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var nonce = $btn.data("nonce");

        if (typeof zeko_jobs_ajax === 'undefined') return;

        var searchName = prompt("Name this search:", "My Saved Search");
        if (!searchName) return;

        var searchTerm = $("input[name='job_search']").val() || "";
        var location = $("input[name='job_location']").val() || "";
        var jobType = $("select[name='job_type']").val() || "";
        var salaryMin = $("input[name='salary_min']").val() || "";
        var salaryMax = $("input[name='salary_max']").val() || "";
        var emailAlerts = confirm("Enable email alerts for new matching jobs?");

        $btn.prop("disabled", true).text("Saving...");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_save_search",
                nonce: nonce,
                name: searchName,
                search_term: searchTerm,
                location: location,
                job_type: jobType,
                salary_min: salaryMin,
                salary_max: salaryMax,
                email_alerts: emailAlerts ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    zekoShowMessage($("body"), zekoI18n().searchSaved || "Search saved.", "success");
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not save search.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            },
            complete: function() {
                $btn.prop("disabled", false).text("Save This Search");
            }
        });
    });

    /* =============================================
       Dashboard — Status badge click (seeker timeline)
       ============================================= */
    $(document).on("click", ".zeko-status-badge[data-application-id]", function() {
        var $badge = $(this);
        var appId = $badge.data("application-id");
        var $timeline = $("#zeko-timeline-" + appId);
        if ($timeline.length) {
            $timeline.slideToggle(200);
        }
    });

    /* =============================================
       Dashboard — Saved searches
       ============================================= */
    $(document).on("click", ".zeko-delete-search-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var searchId = $btn.data("search-id");
        var nonce = $btn.data("nonce");

        if (!confirm("Delete this saved search?")) return;
        if (typeof zeko_jobs_ajax === 'undefined') return;

        var $card = $btn.closest(".zeko-saved-search-card");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_delete_saved_search",
                nonce: nonce,
                search_id: searchId
            },
            success: function(response) {
                if (response.success) {
                    $card.remove();
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not delete search.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });
    /* =============================================
       Dashboard — Document upload and delete
       ============================================= */
    $(document).on("click", ".zeko-upload-document-btn", function() {
        var $btn = $(this);
        var nonce = $btn.data("nonce");
        var fileInput = document.getElementById("zeko-document-upload");
        var applicationId = $("#zeko-document-application-id").val();
        var label = ($("#zeko-resume-label").val() || "").trim();

        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            zekoShowMessage($("body"), "Please select a file.", "error");
            return;
        }

        var formData = new FormData();
        formData.append("action", "zeko_job_upload_document");
        formData.append("nonce", nonce);
        formData.append("document", fileInput.files[0]);
        formData.append("application_id", applicationId);
        if (label) formData.append("label", label);

        $btn.prop("disabled", true).text("Uploading...");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    zekoShowMessage($("body"), response.data.message || "Document uploaded.", "success");
                    location.reload();
                } else {
                    zekoShowMessage($("body"), response.data.message || "Upload failed.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            },
            complete: function() {
                $btn.prop("disabled", false).html('<span class="dashicons dashicons-upload" aria-hidden="true"></span> Upload Resume');
            }
        });
    });

    $(document).on("click", ".zeko-delete-document-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var documentId = $btn.data("document-id");
        var nonce = $btn.data("nonce");

        if (!confirm("Delete this document?")) return;
        if (typeof zeko_jobs_ajax === 'undefined') return;

        var $card = $btn.closest(".zeko-document-card");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_delete_document",
                nonce: nonce,
                document_id: documentId
            },
            success: function(response) {
                if (response.success) {
                    $card.remove();
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not delete document.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    /* Set default resume */
    $(document).on("click", ".zeko-set-default-resume-btn", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;
        var $btn = $(this);
        var docId = $btn.data("doc-id");
        var nonce = $btn.data("nonce");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_set_default_resume",
                nonce: nonce,
                document_id: docId
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not set default.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    /* Edit document label inline */
    $(document).on("click", ".zeko-edit-doc-label-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var docId = $btn.data("doc-id");
        var currentLabel = $btn.data("label") || "";
        var newLabel = prompt("Resume label:", currentLabel);
        if (newLabel === null) return;
        if (typeof zeko_jobs_ajax === 'undefined') return;
        var nonce = $btn.closest(".zeko-document-card").find(".zeko-delete-document-btn").data("nonce");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_update_doc_label",
                nonce: nonce,
                document_id: docId,
                label: newLabel
            },
            success: function(response) {
                if (response.success) {
                    var $label = $(".zeko-document-label[data-doc-id='" + docId + "']");
                    $label.text(newLabel || $label.text());
                    $btn.data("label", newLabel);
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not update label.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    /* Resume select in application form — update hidden field */
    $(document).on("change", ".zeko-resume-select", function() {
        var $sel = $(this);
        var $wrapper = $sel.closest(".zeko-field");
        var $hiddenUrl = $wrapper.find('input[name="saved_resume_url"]');
        var $option = $sel.find(":selected");
        var url = $option.data("url");
        if (url) $hiddenUrl.val(url);
    });

    /* =============================================
       Dashboard — Job Alerts
       ============================================= */
    $(document).on("click", "#zeko-save-alert-btn", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;
        var $btn = $(this);
        var editId = $("#zeko-alert-edit-id").val();
        var data = {
            action: "zeko_job_save_alert",
            nonce: typeof zeko_jobs_ajax !== "undefined" ? zeko_jobs_ajax.nonce : "",
            name: ($("#zeko-alert-name").val() || "").trim(),
            keyword: ($("#zeko-alert-keyword").val() || "").trim(),
            location: ($("#zeko-alert-location").val() || "").trim(),
            type: $("#zeko-alert-type").val(),
            frequency: $("#zeko-alert-frequency").val()
        };
        if (editId) data.alert_id = editId;
        if (!data.name && !data.keyword && !data.location) {
            zekoShowMessage($("#zeko-alert-form-message"), "Please provide a name, keyword, or location.", "error");
            return;
        }
        $btn.prop("disabled", true);
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: data,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    zekoShowMessage($("#zeko-alert-form-message"), response.data.message || "Could not save alert.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("#zeko-alert-form-message"), zekoI18n().networkError || "Network error.", "error");
            },
            complete: function() { $btn.prop("disabled", false); }
        });
    });

    $(document).on("click", ".zeko-edit-alert-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        $("#zeko-alert-form-title").text("Edit Alert");
        $("#zeko-alert-name").val($btn.data("name"));
        $("#zeko-alert-keyword").val($btn.data("keyword"));
        $("#zeko-alert-location").val($btn.data("location"));
        $("#zeko-alert-type").val($btn.data("type"));
        $("#zeko-alert-frequency").val($btn.data("frequency"));
        $("#zeko-alert-edit-id").val($btn.data("alert-id"));
        $("#zeko-save-alert-btn").text("Update Alert");
        $("#zeko-cancel-edit-alert-btn").show();
        $("html, body").animate({ scrollTop: $("#zeko-alert-form-title").offset().top - 100 }, 300);
    });

    $(document).on("click", "#zeko-cancel-edit-alert-btn", function(e) {
        e.preventDefault();
        $("#zeko-alert-form-title").text("Create New Alert");
        $("#zeko-alert-name, #zeko-alert-keyword, #zeko-alert-location").val("");
        $("#zeko-alert-type, #zeko-alert-frequency").val("").trigger("change");
        $("#zeko-alert-edit-id").val("");
        $("#zeko-save-alert-btn").text("Create Alert");
        $(this).hide();
    });

    $(document).on("click", ".zeko-delete-alert-btn", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;
        var $btn = $(this);
        if (!confirm("Delete this alert?")) return;
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_delete_alert",
                nonce: $btn.data("nonce"),
                alert_id: $btn.data("alert-id")
            },
            success: function(response) {
                if (response.success) {
                    $btn.closest(".zeko-alert-card").remove();
                    if (!$(".zeko-alert-card").length) location.reload();
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not delete.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    $(document).on("click", ".zeko-toggle-alert-btn", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;
        var $btn = $(this);
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_toggle_alert",
                nonce: $btn.data("nonce"),
                alert_id: $btn.data("alert-id")
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    zekoShowMessage($("body"), response.data.message || "Could not toggle.", "error");
                }
            },
            error: function() {
                zekoShowMessage($("body"), zekoI18n().networkError || "Network error.", "error");
            }
        });
    });

    $(document).on("click", "#zeko-save-template-btn", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;
        var $name = $("#zeko-tpl-name");
        var $content = $("#zeko-tpl-content");
        var $editId = $("#zeko-tpl-edit-id");
        var name = $name.val().trim();
        var content = $content.val().trim();
        if (!name || !content) return;

        var data = {
            action: "zeko_job_save_template",
            nonce: zeko_jobs_ajax.dashboard_nonce,
            name: name,
            content: content
        };
        if ($editId.val()) {
            data.template_id = $editId.val();
        }

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: data,
            success: function(response) {
                if (response.success) {
                    $name.val("");
                    $content.val("");
                    $editId.val("");
                    $("#zeko-cancel-edit-template-btn").hide();
                    var $list = $("#zeko-cover-templates-list");
                    $list.find(".zeko-no-data").remove();
                    $list.empty();
                    var templates = response.data.templates;
                    for (var id in templates) {
                        var tpl = templates[id];
                        var $card = $('<div class="zeko-document-card" data-template-id="' + id + '">' +
                            '<div class="zeko-document-info"><strong></strong><span></span></div>' +
                            '<div class="zeko-saved-search-actions">' +
                            '<button type="button" class="button button-small zeko-edit-template-btn" data-template-id="' + id + '" data-name="" data-content=""><span class="dashicons dashicons-edit"></span></button>' +
                            '<button type="button" class="button button-small button-link-delete zeko-delete-template-btn" data-template-id="' + id + '" data-nonce="' + zeko_jobs_ajax.dashboard_nonce + '"><span class="dashicons dashicons-trash"></span></button>' +
                            '</div></div>');
                        $card.find("strong").text(tpl.name);
                        $card.find("span").first().text(tpl.content.substring(0, 80) + (tpl.content.length > 80 ? "..." : ""));
                        $card.find(".zeko-edit-template-btn").attr("data-name", tpl.name).attr("data-content", tpl.content);
                        $list.append($card);
                    }
                }
            }
        });
    });

    $(document).on("click", ".zeko-edit-template-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        $("#zeko-tpl-name").val($btn.data("name"));
        $("#zeko-tpl-content").val($btn.data("content"));
        $("#zeko-tpl-edit-id").val($btn.data("template-id"));
        $("#zeko-cancel-edit-template-btn").show();
    });

    $(document).on("click", "#zeko-cancel-edit-template-btn", function(e) {
        e.preventDefault();
        $("#zeko-tpl-name").val("");
        $("#zeko-tpl-content").val("");
        $("#zeko-tpl-edit-id").val("");
        $(this).hide();
    });

    $(document).on("click", ".zeko-delete-template-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var templateId = $btn.data("template-id");
        var nonce = $btn.data("nonce");
        if (!confirm("Delete this template?")) return;
        if (typeof zeko_jobs_ajax === 'undefined') return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_delete_template", nonce: nonce, template_id: templateId },
            success: function(response) {
                if (response.success) {
                    $btn.closest(".zeko-document-card").remove();
                    if (!response.data.templates || Object.keys(response.data.templates).length === 0) {
                        $("#zeko-cover-templates-list").html('<p class="zeko-no-data">No templates saved yet.</p>');
                    }
                }
            }
        });
    });

    $(document).on("submit", "#zeko-company-form", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;
        var $form = $(this);
        var $msg = $("#zeko-company-message");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: $form.serialize() + "&action=zeko_job_save_company",
            beforeSend: function() { $form.find("button[type=submit]").prop("disabled", true); },
            success: function(response) {
                if (response.success) {
                    $msg.css("color", "green").text(response.data.message);
                    if ($("#zeko-company-new").val() === "1") {
                        setTimeout(function() { window.location.reload(); }, 600);
                    }
                } else {
                    $msg.css("color", "red").text(response.data.message || "Could not save.");
                }
            },
            error: function() { $msg.css("color", "red").text("Network error."); },
            complete: function() { $form.find("button[type=submit]").prop("disabled", false); }
        });
    });

    /* =============================================
       Dashboard — Multi-company switcher + add
       ============================================= */
    function zekoLoadCompanies() {
        var $json = $("#zeko-companies-json");
        if (!$json.length) return [];
        try {
            var parsed = JSON.parse($json.text());
            return Array.isArray(parsed) ? parsed : [];
        } catch (err) {
            return [];
        }
    }

    function zekoFillCompanyForm(company) {
        if (!company) return;
        $("#zeko-company-id").val(company.id || "");
        $("#zeko-company-name").val(company.name || "");
        $("#zeko-company-description").val(company.description || "");
        $("#zeko-company-industry").val(company.industry || "");
        $("#zeko-company-size").val(company.size || "");
        $("#zeko-company-website").val(company.website || "");
        $("#zeko-company-location").val(company.location || "");
        $("#zeko-company-founded").val(company.founded_year || "");
        $("#zeko-company-linkedin").val(company.linkedin || "");
        $("#zeko-company-twitter").val(company.twitter || "");
        $("#zeko-company-cover").val(company.cover_url || "");
        if (company.accent_color) {
            $("#zeko-company-accent").val(company.accent_color);
        }
        $("#zeko-company-video").val(company.video_url || "");
    }

    $(document).on("change", "#zeko-company-switcher", function() {
        var id = $(this).val();
        var companies = zekoLoadCompanies();
        var match = null;
        for (var i = 0; i < companies.length; i++) {
            if (String(companies[i].id) === String(id)) {
                match = companies[i];
                break;
            }
        }
        zekoFillCompanyForm(match);
    });

    $(document).on("click", ".zeko-company-add-btn", function() {
        $("#zeko-company-id").val("");
        $("#zeko-company-new").val("1");
        $("#zeko-company-form").find("input[type=text],input[type=url],input[type=number],textarea,select,input[type=color]").val("");
        $("#zeko-company-form").find("input[type=color]").val("#6366f1");
        $("#zeko-company-switcher").val("");
        $("#zeko-company-name").focus();
    });

    // When a specific company is selected for editing, stop force-create mode.
    $(document).on("change", "#zeko-company-switcher", function() {
        $("#zeko-company-new").val("0");
    });

    /* =============================================
       Dashboard — Company blog
       ============================================= */
    $(document).on("submit", "#zeko-company-post-form", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === 'undefined') return;
        var $form = $(this);
        var $msg = $("#zeko-blog-message");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: $form.serialize() + "&action=zeko_job_save_company_post",
            beforeSend: function() { $form.find("button[type=submit]").prop("disabled", true); },
            success: function(response) {
                if (response.success) {
                    $msg.css("color", "green").text(response.data.message);
                    setTimeout(function() { window.location.reload(); }, 600);
                } else {
                    $msg.css("color", "red").text(response.data.message || "Could not save.");
                }
            },
            error: function() { $msg.css("color", "red").text("Network error."); },
            complete: function() { $form.find("button[type=submit]").prop("disabled", false); }
        });
    });

    $(document).on("click", ".zeko-company-post-edit", function() {
        var id = $(this).data("blog-id");
        var $item = $(".zeko-company-post-item[data-blog-id='" + id + "']");
        if (!$item.length) return;
        $("#zeko-blog-id").val(id);
        $("#zeko-blog-title").val($item.find("strong").text());
        $(".zeko-blog-cancel-edit").show();
        $("html, body").animate({ scrollTop: $("#zeko-company-post-form").offset().top - 100 }, 300);
    });

    $(document).on("click", ".zeko-company-post-delete", function() {
        if (!window.confirm("Delete this blog post?")) return;
        var id = $(this).data("blog-id");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_delete_company_post", nonce: zeko_jobs_ajax.dashboard_nonce, blog_id: id },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                }
            }
        });
    });

    $(document).on("click", ".zeko-blog-cancel-edit", function() {
        $("#zeko-blog-id").val("");
        $("#zeko-blog-title").val("");
        $("#zeko-blog-content").val("");
        $(this).hide();
    });

    /* =============================================
       Dashboard — Sidebar navigation
       ============================================= */
    function zekoDashboardNavigate(hash) {
        if (!hash || !hash.startsWith("#")) return;
        var $target = $(hash);
        if (!$target.length || !$target.hasClass("zeko-dashboard-section")) return;
        $(".zeko-dashboard-nav a").removeClass("active");
        $(".zeko-dashboard-nav a[href='" + hash + "']").addClass("active");
        $(".zeko-dashboard-section").hide();
        $target.show();
        $("html, body").animate({ scrollTop: $target.offset().top - 100 }, 300);
    }

    // --- Dashboard: initial state — hide all sections except the active one ---
    var $sections = $(".zeko-dashboard-section");
    if ($sections.length) {
        $sections.hide();
        var hash = window.location.hash;
        if (hash && $(hash).length && $(hash).hasClass("zeko-dashboard-section")) {
            $(hash).show();
            $(".zeko-dashboard-nav a").removeClass("active");
            $(".zeko-dashboard-nav a[href='" + hash + "']").addClass("active");
        } else {
            var $initial = $sections.filter(function() {
                return $(this).attr("id") === "zeko-dashboard-overview";
            });
            if ($initial.length) {
                $initial.show();
            } else {
                $sections.first().show();
            }
        }
    }

    // Direct binding — fires at element level, before any delegated handlers
    $(".zeko-dashboard-nav a").on("click", function(e) {
        var $link = $(this);
        if ($link.hasClass("zeko-nav-external")) { return; }
        e.preventDefault();
        e.stopPropagation();
        var target = $link.attr("href");
        if (target && target.startsWith("#")) {
            window.location.hash = target;
            zekoDashboardNavigate(target);
        }
    });

    // Also respond to browser back/forward hash changes
    $(window).on("hashchange", function() {
        if ($sections.length) {
            zekoDashboardNavigate(window.location.hash);
        }
    });

    /* =============================================
       Dashboard — Search filter
       ============================================= */
    $(document).on("input", "#zeko-dashboard-search", function() {
        var query = $(this).val().toLowerCase();
        $(".zeko-job-card, .zeko-application-card").each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(query));
        });
    });

    /* =============================================
       Dashboard — Paginated lists
       ============================================= */
    function zekoPaginateLists() {
        $(".zeko-paginated-list").each(function() {
            var $list = $(this);
            var perPage = parseInt($list.data("per-page")) || 10;
            var $items = $list.children();
            var total = $items.length;
            var currentPage = 1;
            var totalPages = Math.ceil(total / perPage);
            var $pagination = $list.next(".zeko-pagination");

            function showPage(page) {
                currentPage = page;
                var start = (page - 1) * perPage;
                var end = start + perPage;
                $items.hide().slice(start, end).show();
                renderPagination();
            }

            function renderPagination() {
                if (totalPages <= 1) { $pagination.empty(); return; }
                var html = '';
                html += '<button class="button button-small" data-page="prev" ' + (currentPage === 1 ? 'disabled' : '') + '>&laquo;</button>';
                for (var i = 1; i <= totalPages; i++) {
                    html += '<button class="button button-small ' + (i === currentPage ? 'button-primary' : '') + '" data-page="' + i + '">' + i + '</button>';
                }
                html += '<button class="button button-small" data-page="next" ' + (currentPage === totalPages ? 'disabled' : '') + '>&raquo;</button>';
                $pagination.html(html);
            }

            $pagination.off("click").on("click", "button", function() {
                var pg = $(this).data("page");
                if (pg === "prev") showPage(currentPage - 1);
                else if (pg === "next") showPage(currentPage + 1);
                else showPage(parseInt(pg));
            });

            if (total > 0) showPage(1);
        });
    }
    zekoPaginateLists();

    /* =============================================
       Dashboard — Job card actions
       ============================================= */
    $(document).on("click", ".zeko-duplicate-job-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");
        $btn.prop("disabled", true);
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_duplicate", nonce: nonce, job_id: jobId },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || "Could not duplicate.");
                    $btn.prop("disabled", false);
                }
            },
            error: function() { alert("Network error."); $btn.prop("disabled", false); }
        });
    });

    $(document).on("click", ".zeko-delete-job-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");
        if (!confirm("Delete this job? This cannot be undone.")) return;
        $btn.prop("disabled", true);
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_delete", nonce: nonce, job_id: jobId },
            success: function(response) {
                if (response.success) {
                    $btn.closest(".zeko-job-card").fadeOut(300, function() { $(this).remove(); zekoPaginateLists(); });
                } else {
                    alert(response.data.message || "Could not delete.");
                    $btn.prop("disabled", false);
                }
            },
            error: function() { alert("Network error."); $btn.prop("disabled", false); }
        });
    });

    $(document).on("click", ".zeko-renew-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");
        $btn.prop("disabled", true);
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_renew", nonce: nonce, job_id: jobId, renew_days: 30 },
            success: function(response) {
                if (response.success) {
                    var $card = $btn.closest(".zeko-job-card");
                    $card.find(".zeko-status-badge").removeClass("zeko-status-expired").addClass("zeko-status-publish").text("Publish");
                    $card.attr("data-status", "publish");
                    $btn.remove();
                    if (typeof zekoShowMessage === "function") {
                        zekoShowMessage($card, response.data.message || "Job renewed!", "success");
                    }
                } else {
                    alert(response.data.message || "Could not renew.");
                    $btn.prop("disabled", false);
                }
            },
            error: function() { alert("Network error."); $btn.prop("disabled", false); }
        });
    });

    $(document).on("change", ".zeko-job-status-select", function() {
        var $select = $(this);
        var jobId = $select.data("job-id");
        var nonce = $select.data("nonce");
        var newStatus = $select.val();
        var $card = $select.closest(".zeko-job-card");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_update_status", nonce: nonce, job_id: jobId, status: newStatus },
            success: function(response) {
                if (response.success) {
                    $card.attr("data-status", newStatus);
                    $card.find(".zeko-status-badge").removeClass("zeko-status-draft zeko-status-publish zeko-status-paused zeko-status-closed zeko-status-expired").addClass("zeko-status-" + newStatus).text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
                } else {
                    alert(response.data.message || "Update failed.");
                }
            }
        });
    });

    $(document).on("click", ".zeko-select-all-jobs", function() {
        var checked = $(this).prop("checked");
        $(this).closest(".zeko-dashboard-section").find(".zeko-job-checkbox").prop("checked", checked);
    });

    $(document).on("click", ".zeko-bulk-apply-btn", function() {
        var $section = $(this).closest(".zeko-dashboard-section");
        var action = $section.find("#zeko-bulk-action-select").val();
        if (!action) return;
        var jobIds = [];
        $section.find(".zeko-job-checkbox:checked").each(function() { jobIds.push($(this).data("job-id")); });
        if (!jobIds.length) { alert("Select jobs first."); return; }
        if (action === "delete" && !confirm("Delete selected jobs?")) return;
        var nonce = $section.find(".zeko-job-status-select").first().data("nonce");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_bulk_action", nonce: nonce, job_ids: jobIds, bulk_action: action },
            success: function() { location.reload(); }
        });
    });

    /* =============================================
       Dashboard — Application filters
       ============================================= */
    $(document).on("change", "#zeko-app-filter-status, #zeko-app-filter-job", function() {
        var status = $("#zeko-app-filter-status").val();
        var jobId = $("#zeko-app-filter-job").val();
        $(".zeko-applications-list .zeko-application-card").each(function() {
            var $card = $(this);
            var matchStatus = !status || $card.data("status") === status;
            var matchJob = !jobId || String($card.data("job-id")) === String(jobId);
            $card.toggle(matchStatus && matchJob);
        });
    });

    /* =============================================
       Dashboard — Pipeline drag-and-drop (SortableJS)
       ============================================= */
    function zekoInitKanban() {
        var $board = $(".zeko-kanban-board");
        if (!$board.length) return;
        var nonce = $board.data("nonce");
        if (!nonce || typeof Sortable === "undefined") return;

        $board.find(".zeko-kanban-cards").each(function() {
            Sortable.create(this, {
                group: "zeko-pipeline",
                animation: 150,
                ghostClass: "zeko-kanban-ghost",
                chosenClass: "zeko-kanban-chosen",
                dragClass: "zeko-kanban-drag",
                handle: ".zeko-kanban-card",
                onEnd: function(evt) {
                    var $card = $(evt.item);
                    var $newColumn = $(evt.to).closest(".zeko-kanban-column");
                    var newStatus = $newColumn.data("status");
                    var appId = $card.data("app-id");

                    $(".zeko-kanban-column").each(function() {
                        $(this).find(".zeko-kanban-count").text($(this).find(".zeko-kanban-card").length);
                    });

                    var $empty = $newColumn.find(".zeko-kanban-empty");
                    if ($empty.length && $newColumn.find(".zeko-kanban-card").length > 0) {
                        $empty.remove();
                    }

                    $.ajax({
                        url: zeko_jobs_ajax.ajax_url,
                        type: "POST",
                        data: {
                            action: "zeko_job_update_status",
                            nonce: nonce,
                            application_id: appId,
                            status: newStatus
                        },
                        error: function() {
                            if (evt.from !== evt.to) {
                                $(evt.from).append(evt.item);
                                $(".zeko-kanban-column").each(function() {
                                    $(this).find(".zeko-kanban-count").text($(this).find(".zeko-kanban-card").length);
                                });
                            }
                        }
                    });
                }
            });
        });
    }
    zekoInitKanban();

    /* =============================================
       Kanban — Responsive horizontal scroll on mobile
       ============================================= */
    function zekoKanbanResponsive() {
        var $board = $(".zeko-kanban-board");
        if (!$board.length) return;
        if ($(window).width() <= 600) {
            $board.addClass("is-responsive");
        } else {
            $board.removeClass("is-responsive");
        }
    }
    zekoKanbanResponsive();
    $(window).on("resize", zekoKanbanResponsive);

    /* =============================================
       Kanban — Batch stage move
       ============================================= */
    $(document).on("change", ".zeko-kanban-checkbox", function() {
        var total = $(".zeko-kanban-checkbox:checked").length;
        if (total > 0) {
            $(".zeko-kanban-bulk-actions").show();
            $(".zeko-bulk-count").text(total + " selected");
        } else {
            $(".zeko-kanban-bulk-actions").hide();
            $(".zeko-bulk-stage-select").val("");
        }
    });

    $(document).on("click", ".zeko-bulk-move-btn", function() {
        var newStatus = $(".zeko-bulk-stage-select").val();
        if (!newStatus) {
            alert("Please select a target stage.");
            return;
        }
        var $btn = $(this);
        var $board = $(".zeko-kanban-board");
        var nonce = $board.data("nonce");
        var appIds = [];
        $(".zeko-kanban-checkbox:checked").each(function() {
            appIds.push($(this).data("app-id"));
        });
        if (!appIds.length) return;

        $btn.prop("disabled", true);
        var done = 0;
        var total = appIds.length;

        appIds.forEach(function(appId) {
            $.ajax({
                url: zeko_jobs_ajax.ajax_url,
                type: "POST",
                data: {
                    action: "zeko_job_update_status",
                    nonce: nonce,
                    application_id: appId,
                    status: newStatus
                },
                success: function() {
                    var $card = $(".zeko-kanban-card[data-app-id='" + appId + "']");
                    var $targetCol = $(".zeko-kanban-column[data-status='" + newStatus + "'] .zeko-kanban-cards");
                    if ($card.length && $targetCol.length) {
                        $card.find(".zeko-kanban-checkbox").prop("checked", false);
                        $targetCol.append($card);
                    }
                    done++;
                    if (done === total) {
                        $(".zeko-kanban-column").each(function() {
                            $(this).find(".zeko-kanban-count").text($(this).find(".zeko-kanban-card").length);
                            if ($(this).find(".zeko-kanban-card").length > 0) {
                                $(this).find(".zeko-kanban-empty").remove();
                            }
                        });
                        $(".zeko-kanban-bulk-actions").hide();
                        $(".zeko-bulk-stage-select").val("");
                        $btn.prop("disabled", false);
                        zekoAnnounce(total + " application(s) moved to " + newStatus + ".");
                    }
                },
                error: function() {
                    done++;
                    if (done === total) $btn.prop("disabled", false);
                }
            });
        });
    });

    $(document).on("click", ".zeko-advance-app-btn", function(e) {
        e.preventDefault();
    });

    /* =============================================
       Dashboard — Calendar navigation
       ============================================= */
    $(document).on("click", ".zeko-cal-nav-btn", function() {
        var month = $(this).data("month");
        if (month) {
            window.location.href = window.location.pathname + "?cal_month=" + month + "#zeko-dashboard-calendar";
        }
    });

    /* =============================================
       Dashboard — Pipeline search/filter
       ============================================= */
    function zekoFilterPipeline() {
        var search = $(".zeko-pipeline-search").val().toLowerCase();
        var jobFilter = $(".zeko-pipeline-filter-job").val();
        var dateFilter = $(".zeko-pipeline-filter-date").val();
        $(".zeko-kanban-card").each(function() {
            var $card = $(this);
            var cardSearch = ($card.data("search") || "").toLowerCase();
            var cardJobId = String($card.data("job-id") || "");
            var cardDate = $card.data("date") || "";
            var show = true;
            if (search && cardSearch.indexOf(search) === -1) show = false;
            if (jobFilter && cardJobId !== jobFilter) show = false;
            if (dateFilter && cardDate !== dateFilter) show = false;
            $card.toggle(show);
        });
        $(".zeko-kanban-column").each(function() {
            var visible = $(this).find(".zeko-kanban-card:visible").length;
            $(this).find(".zeko-kanban-count").text(visible);
        });
    }

    $(document).on("input", ".zeko-pipeline-search", zekoFilterPipeline);
    $(document).on("change", ".zeko-pipeline-filter-job, .zeko-pipeline-filter-date", zekoFilterPipeline);
    $(document).on("click", ".zeko-pipeline-clear-filters", function() {
        $(".zeko-pipeline-search").val("");
        $(".zeko-pipeline-filter-job").val("");
        $(".zeko-pipeline-filter-date").val("");
        zekoFilterPipeline();
    });

    /* =============================================
       Dashboard — Email Templates
       ============================================= */
    $(document).on("submit", ".zeko-email-template-form", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === "undefined") return;
        var $form = $(this);
        var $msg = $("#zeko-email-template-message");
        var key = "custom_" + Date.now();
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: $form.serialize() + "&action=zeko_job_save_email_template&template_key=" + key,
            beforeSend: function() { $form.find("button[type=submit]").prop("disabled", true); },
            success: function(response) {
                if (response.success) {
                    $msg.css("color", "green").text(response.data.message);
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    $msg.css("color", "red").text(response.data.message || "Could not save.");
                }
            },
            error: function() { $msg.css("color", "red").text("Network error."); },
            complete: function() { $form.find("button[type=submit]").prop("disabled", false); }
        });
    });

    $(document).on("click", ".zeko-delete-email-template-btn", function() {
        if (typeof zeko_jobs_ajax === "undefined") return;
        var key = $(this).data("key");
        if (!confirm("Delete this template?")) return;
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_delete_email_template",
            nonce: $(".zeko-email-template-form input[name=nonce]").val(),
            template_key: key
        }, function(response) {
            if (response.success) location.reload();
        });
    });

    /* =============================================
       Dashboard — Bulk Email
       ============================================= */
    $(document).on("change", ".zeko-employer-app-card .zeko-bulk-select, .zeko-job-checkbox", function() {
        var count = $(".zeko-employer-app-card .zeko-bulk-select:checked, .zeko-job-checkbox:checked").length;
        if (count > 0) {
            $(".zeko-bulk-email-btn").show();
        } else {
            $(".zeko-bulk-email-btn").hide();
        }
    });

    $(document).on("click", ".zeko-bulk-email-btn", function() {
        var selected = [];
        $(".zeko-employer-app-card .zeko-bulk-select:checked").each(function() {
            selected.push($(this).closest(".zeko-employer-app-card").data("application-id") || $(this).val());
        });
        if (selected.length === 0) {
            alert("Please select applicants first.");
            return;
        }
        $(".zeko-bulk-email-count").text(selected.length + " applicant(s) selected.");
        $("#zeko-bulk-email-modal").show().data("ids", selected);
    });

    $(document).on("change", ".zeko-bulk-email-template-select", function() {
        var $opt = $(this).find(":selected");
        if ($opt.val()) {
            $(".zeko-bulk-email-subject").val($opt.data("subject") || "");
            $(".zeko-bulk-email-body").val($opt.data("body") || "");
        }
    });

    $(document).on("click", ".zeko-bulk-email-send-btn", function() {
        if (typeof zeko_jobs_ajax === "undefined") return;
        var $btn = $(this);
        var ids = $("#zeko-bulk-email-modal").data("ids") || [];
        var subject = $(".zeko-bulk-email-subject").val();
        var body = $(".zeko-bulk-email-body").val();
        if (!subject || !body) { alert("Subject and message are required."); return; }
        $btn.prop("disabled", true).text("Sending...");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_bulk_email",
                nonce: $(".zeko-email-template-form input[name=nonce]").val(),
                application_ids: ids,
                email_subject: subject,
                email_body: body
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    $("#zeko-bulk-email-modal").hide();
                } else {
                    alert(response.data.message || "Failed to send.");
                }
            },
            error: function() { alert("Network error."); },
            complete: function() { $btn.prop("disabled", false).text("Send Emails"); }
        });
    });

    /* =============================================
       Dashboard — Star Rating
       ============================================= */
    $(document).on("click", ".zeko-star", function() {
        var $container = $(this).closest(".zeko-star-rating");
        var value = $(this).data("value");
        $container.data("rating", value);
        $container.find(".zeko-star").each(function() {
            var v = $(this).data("value");
            $(this).removeClass("dashicons-star-empty dashicons-star-filled").addClass(v <= value ? "dashicons-star-filled" : "dashicons-star-empty");
        });
        var labels = ["", "Poor", "Fair", "Good", "Very Good", "Excellent"];
        $container.find(".zeko-rating-label").text(labels[value] || "");

        if (typeof zeko_jobs_ajax !== "undefined") {
            var appId = $container.data("application-id");
            if (appId) {
                $.post(zeko_jobs_ajax.ajax_url, {
                    action: "zeko_job_save_rating",
                    nonce: $(".zeko-kanban-board").data("nonce"),
                    application_id: appId,
                    rating: value
                });
            }
        }
    });

    $(document).on("mouseenter", ".zeko-star", function() {
        var $container = $(this).closest(".zeko-star-rating");
        var hoverVal = $(this).data("value");
        $container.find(".zeko-star").each(function() {
            var v = $(this).data("value");
            $(this).removeClass("dashicons-star-empty dashicons-star-filled").addClass(v <= hoverVal ? "dashicons-star-filled" : "dashicons-star-empty");
        });
    });

    $(document).on("mouseleave", ".zeko-star-rating", function() {
        var $container = $(this);
        var currentRating = $container.data("rating") || 0;
        $container.find(".zeko-star").each(function() {
            var v = $(this).data("value");
            $(this).removeClass("dashicons-star-empty dashicons-star-filled").addClass(v <= currentRating ? "dashicons-star-filled" : "dashicons-star-empty");
        });
    });

    /* =============================================
       Dashboard — Application detail modal
       ============================================= */
    $(document).on("click", ".zeko-view-app-btn", function(e) {
        e.preventDefault();
        var appId = $(this).data("application-id");
        var nonce = $(this).data("nonce");
        var $modal = $("#zeko-app-detail-modal");
        $modal.show().find(".zeko-app-detail-loading").show();
        $modal.find(".zeko-app-detail-content").hide();
        $modal.data("app-id", appId);
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "GET",
            data: { action: "zeko_job_get_detail", application_id: appId, nonce: nonce },
            success: function(response) {
                if (response.success) {
                    var app = response.data.application;
                    var notes = response.data.notes;
                    var history = response.data.history;
                    $modal.find(".zeko-app-detail-job-title").text(app.job_title);
                    $modal.find(".zeko-app-detail-seeker").text("Applicant: " + (app.seeker_name || app.seeker_login || "Unknown"));
                    $modal.find(".zeko-app-detail-date").text("Applied: " + app.applied_at);
                    $modal.find(".zeko-app-detail-cover-letter").html(app.cover_letter ? "<p>" + escapeHtml(app.cover_letter).replace(/\n/g, "<br>") + "</p>" : "<p><em>No cover letter</em></p>");
                    $modal.find(".zeko-app-detail-status-select").val(app.status).data("application-id", app.id);
                    var rating = app.rating || 0;
                    var $starRating = $modal.find(".zeko-star-rating");
                    $starRating.data("rating", rating).data("application-id", app.id);
                    $starRating.find(".zeko-star").each(function() {
                        var v = $(this).data("value");
                        $(this).removeClass("dashicons-star-empty dashicons-star-filled").addClass(v <= rating ? "dashicons-star-filled" : "dashicons-star-empty");
                    });
                    var labels = ["", "Poor", "Fair", "Good", "Very Good", "Excellent"];
                    $starRating.find(".zeko-rating-label").text(labels[rating] || "");
                    var notesHtml = "";
                    if (notes && notes.length) {
                        notes.forEach(function(n) {
                            var safeNote = escapeHtml(n.note || '');
                            var safeDate = escapeHtml(n.created_at || '');
                            notesHtml += "<div class='zeko-note-item'><p>" + safeNote + "</p><small>" + safeDate + "</small></div>";
                        });
                    } else {
                        notesHtml = "<p><em>No notes yet</em></p>";
                    }
                    $modal.find(".zeko-app-notes-list").html(notesHtml);
                    var histHtml = "";
                    if (history && history.length) {
                        history.forEach(function(h) { histHtml += "<li><strong>" + h.to_status + "</strong> — " + h.created_at + "</li>"; });
                    } else {
                        histHtml = "<li><em>No history</em></li>";
                    }
                    $modal.find(".zeko-app-history-list").html(histHtml);
                    // Email read receipts.
                    var emailOpens = response.data.email_opens || {};
                    var receiptHtml = "";
                    var receiptTypes = [
                        { key: "application_received", label: "Application received" },
                        { key: "status_changed", label: "Status update" },
                        { key: "interview_scheduled", label: "Interview scheduled" },
                        { key: "application_withdrawn", label: "Withdrawal notice" }
                    ];
                    var hasAny = false;
                    receiptTypes.forEach(function(rt) {
                        if (emailOpens[rt.key] && emailOpens[rt.key].opened) {
                            hasAny = true;
                            receiptHtml += "<div class='zeko-receipt-item'><span class='dashicons dashicons-yes-alt' style='color:#22c55e;'></span> " + rt.label + " — opened " + escapeHtml(emailOpens[rt.key].opened_at) + "</div>";
                        } else {
                            receiptHtml += "<div class='zeko-receipt-item' style='color:var(--color-text-muted);'><span class='dashicons dashicons-minus' style='color:var(--color-text-muted);'></span> " + rt.label + " — not opened</div>";
                        }
                    });
                    if (hasAny) {
                        $modal.find(".zeko-app-email-receipts").html(receiptHtml).show();
                    } else {
                        $modal.find(".zeko-app-email-receipts").html("<p style='color:var(--color-text-muted);font-size:13px;'><em>No email activity tracked yet.</em></p>").show();
                    }
                    $modal.find(".zeko-app-detail-loading").hide();
                    $modal.find(".zeko-app-detail-content").show();
                } else {
                    alert(response.data.message || "Could not load details.");
                    $modal.hide();
                }
            },
            error: function() { alert("Network error."); $modal.hide(); }
        });
    });

    $(document).on("click", ".zeko-modal-close, .zeko-modal-overlay", function() {
        $(this).closest(".zeko-modal").hide();
    });

    $(document).on("click", ".zeko-add-note-btn", function() {
        var $modal = $(this).closest(".zeko-modal");
        var appId = $modal.data("app-id");
        var note = $modal.find(".zeko-note-input").val().trim();
        if (!note) return;
        var nonce = $(this).closest(".zeko-modal").find(".zeko-add-note-btn").data("nonce") || $modal.find(".zeko-status-select").data("nonce");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_add_note", nonce: nonce, application_id: appId, note: note },
            success: function(response) {
                if (response.success) {
                    $modal.find(".zeko-app-notes-list").append("<div class='zeko-note-item'><p>" + note + "</p><small>Just now</small></div>");
                    $modal.find(".zeko-note-input").val("");
                }
            }
        });
    });

    $(document).on("change", ".zeko-app-detail-status-select", function() {
        var $select = $(this);
        var appId = $select.data("application-id");
        var nonce = $select.data("nonce");
        var newStatus = $select.val();
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: { action: "zeko_job_update_status", nonce: nonce, application_id: appId, status: newStatus },
            success: function(response) {
                if (response.success) {
                    $(".zeko-application-card[data-status]").filter(function() {
                        return $(this).find(".zeko-status-select").data("application-id") == appId;
                    }).attr("data-status", newStatus);
                }
            }
        });
    });

    /* =============================================
       Dashboard — Keyboard shortcuts
       ============================================= */
    $(document).on("keydown", function(e) {
        if (e.key === "/" && !e.ctrlKey && !e.metaKey && document.activeElement.tagName !== "INPUT" && document.activeElement.tagName !== "TEXTAREA") {
            e.preventDefault();
            $("#zeko-dashboard-search").focus();
        }
    });

    /* =============================================
       Post Form — Submit (Create / Edit)
       ============================================= */
    $(document).on("submit", "#zeko-job-create-form", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === "undefined") return;

        var $form = $(this);
        var $msg = $form.find("#zeko-job-create-message");
        var $btn = $form.find("button[type='submit']");
        var editJobId = $form.find("input[name='edit_job_id']").val();

        var formData = new FormData($form[0]);
        formData.append("action", "zeko_job_create");
        formData.append("zeko_job_create_nonce", zeko_jobs_ajax.job_create_nonce);

        $btn.prop("disabled", true).text(editJobId ? "Updating..." : "Publishing...");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    if (editJobId) {
                        zekoShowMessage($msg, response.data.message || "Job updated successfully.", "success");
                        setTimeout(function() { window.location.href = "/job-dashboard/"; }, 1000);
                    } else {
                        zekoShowMessage($msg, response.data.message || "Job published successfully.", "success");
                        setTimeout(function() { window.location.href = "/jobs/" + response.data.job_slug + "/"; }, 1000);
                    }
                } else {
                    zekoShowMessage($msg, response.data.message || "Something went wrong.", "error");
                    $btn.prop("disabled", false).text(editJobId ? "Update Job" : "Publish Job");
                }
            },
            error: function() {
                zekoShowMessage($msg, zekoI18n().networkError || "Network error.", "error");
                $btn.prop("disabled", false).text(editJobId ? "Update Job" : "Publish Job");
            }
        });
    });

    /* =============================================
       Post Form — Preview modal
       ============================================= */
    $(document).on("click", "#zeko-job-preview-btn", function() {
        var $form = $(this).closest("form");
        var $modal = $("#zeko-job-preview-modal");
        var title = $form.find("#zeko-job-title").val() || "";
        var location = $form.find("#zeko-job-location").val() || "";
        var type = $form.find("#zeko-job-type").val() || "";
        var salaryMin = parseFloat($form.find("input[name='salary_min']").val()) || 0;
        var salaryMax = parseFloat($form.find("input[name='salary_max']").val()) || 0;
        var desc = "";
        if (typeof tinyMCE !== "undefined" && tinyMCE.get("zeko-job-description")) {
            desc = tinyMCE.get("zeko-job-description").getContent();
        } else {
            desc = $form.find("textarea[name='description']").val() || "";
        }

        $modal.find(".zeko-preview-title").text(title || "Untitled Position");
        $modal.find(".zeko-preview-location").text(location || "No location");
        $modal.find(".zeko-preview-type").text(type);
        var salaryText = "";
        if (salaryMin || salaryMax) {
            salaryText = "$" + salaryMin.toLocaleString() + " - $" + salaryMax.toLocaleString();
        }
        $modal.find(".zeko-preview-salary").text(salaryText);
        $modal.find(".zeko-preview-description").html(sanitizeHtml(desc));
        $modal.attr("aria-hidden", "false").show();
    });

    $(document).on("click", ".zeko-preview-close, .zeko-preview-overlay", function() {
        $("#zeko-job-preview-modal").attr("aria-hidden", "true").hide();
    });

    $(document).on("click", ".zeko-preview-edit", function() {
        $("#zeko-job-preview-modal").attr("aria-hidden", "true").hide();
    });

    $(document).on("click", ".zeko-preview-publish", function() {
        $("#zeko-job-preview-modal").attr("aria-hidden", "true").hide();
        $("#zeko-job-create-form").trigger("submit");
    });

    /* =============================================
       Carousel — Navigation
       ============================================= */
    function zekoUpdateCarouselNav($section) {
        var $track = $section.find(".zeko-carousel-track");
        var $prev = $section.find(".zeko-carousel-prev");
        var $next = $section.find(".zeko-carousel-next");
        var scrollLeft = $track.scrollLeft();
        var maxScroll = $track[0].scrollWidth - $track[0].clientWidth;
        $prev.prop("disabled", scrollLeft <= 5);
        $next.prop("disabled", scrollLeft >= maxScroll - 5);
    }

    $(document).on("click", ".zeko-carousel-next", function() {
        var $section = $(this).closest(".zeko-carousel-section");
        var $track = $section.find(".zeko-carousel-track");
        var cardWidth = $track.find(".zeko-carousel-card").outerWidth(true) + 16;
        $track.animate({ scrollLeft: "+=" + cardWidth }, 300, function() {
            zekoUpdateCarouselNav($section);
        });
    });

    $(document).on("click", ".zeko-carousel-prev", function() {
        var $section = $(this).closest(".zeko-carousel-section");
        var $track = $section.find(".zeko-carousel-track");
        var cardWidth = $track.find(".zeko-carousel-card").outerWidth(true) + 16;
        $track.animate({ scrollLeft: "-=" + cardWidth }, 300, function() {
            zekoUpdateCarouselNav($section);
        });
    });

    $(".zeko-carousel-track").on("scroll", function() {
        zekoUpdateCarouselNav($(this).closest(".zeko-carousel-section"));
    });

    $(".zeko-carousel-section").each(function() {
        zekoUpdateCarouselNav($(this));
    });

    /* =============================================
       Bulk Apply — Selection, Modal, Submit
       ============================================= */
    function zekoUpdateBulkBar() {
        var $bar = $(".zeko-bulk-bar");
        var count = $(".zeko-bulk-select:checked").length;
        if (count > 0) {
            $bar.find(".zeko-bulk-count").text(count + " " + (zekoI18n().jobsSelected || "jobs selected"));
            $bar.show();
        } else {
            $bar.hide();
        }
    }

    $(document).on("change", ".zeko-bulk-select", function() {
        zekoUpdateBulkBar();
    });

    $(document).on("click", ".zeko-bulk-clear-btn", function() {
        $(".zeko-bulk-select:checked").prop("checked", false);
        zekoUpdateBulkBar();
    });

    $(document).on("click", ".zeko-bulk-apply-btn", function() {
        var count = $(".zeko-bulk-select:checked").length;
        if (count === 0) return;

        var $modal = $("#zeko-bulk-apply-modal");
        $modal.find(".zeko-bulk-apply-info").text(count + " " + (zekoI18n().jobsWillApply || "jobs will receive your application"));
        $modal.find(".zeko-bulk-apply-progress").hide();
        $modal.find(".zeko-bulk-apply-results").hide();
        $modal.find("#zeko-bulk-apply-form")[0].reset();
        $modal.find(".zeko-file-preview").hide();
        $modal.find(".zeko-file-upload").show();
        $modal.show();
    });

    $(document).on("submit", "#zeko-bulk-apply-form", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === "undefined") return;

        var $form = $(this);
        var $progress = $form.find(".zeko-bulk-apply-progress");
        var $results = $form.find(".zeko-bulk-apply-results");
        var $submitBtn = $form.find("button[type='submit']");

        var jobIds = [];
        $(".zeko-bulk-select:checked").each(function() {
            jobIds.push($(this).data("job-id"));
        });

        if (jobIds.length === 0) return;

        var formData = new FormData($form[0]);
        formData.append("action", "zeko_job_bulk_apply");
        formData.append("zeko_job_nonce", zeko_jobs_ajax.job_apply_nonce);
        formData.append("job_ids", jobIds.join(","));

        $submitBtn.prop("disabled", true).text("Submitting...");
        $progress.show();
        $form.find(".zeko-progress-fill").css("width", "50%");
        $form.find(".zeko-progress-text").text("Uploading resume...");

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $form.find(".zeko-progress-fill").css("width", "100%");
                $form.find(".zeko-progress-text").text("Done");

                if (response.success) {
                    $results.addClass("zeko-inline-success").html("<p>" + escapeHtml(response.data.message) + "</p>").show();
                    $(".zeko-bulk-select:checked").prop("checked", false);
                    zekoUpdateBulkBar();
                } else {
                    $results.addClass("zeko-inline-error").html("<p>" + escapeHtml(response.data.message || "Something went wrong.") + "</p>").show();
                }
            },
            error: function() {
                $results.addClass("zeko-inline-error").html("<p>" + (zekoI18n().networkError || "Network error.") + "</p>").show();
            },
            complete: function() {
                $submitBtn.prop("disabled", false).text("Submit All Applications");
                setTimeout(function() { $progress.fadeOut(300); }, 1500);
            }
        });
    });

    /* =============================================
       Star Rating Input — Reviews
       ============================================= */
    $(document).on("click", ".zeko-star-btn", function() {
        var $btn = $(this);
        var val = parseInt($btn.data("value"), 10);
        var $group = $btn.closest(".zeko-star-rating-input");
        $group.find("input[name='rating']").val(val);
        $group.find(".zeko-star-btn").each(function() {
            var v = parseInt($(this).data("value"), 10);
            $(this).find(".dashicons").attr("class", "dashicons dashicons-star" + (v <= val ? "" : "-empty"));
        });
    });

    $(document).on("mouseenter", ".zeko-star-btn", function() {
        var $btn = $(this);
        var val = parseInt($btn.data("value"), 10);
        $btn.closest(".zeko-star-rating-input").find(".zeko-star-btn").each(function() {
            var v = parseInt($(this).data("value"), 10);
            $(this).find(".dashicons").attr("class", "dashicons dashicons-star" + (v <= val ? "" : "-empty"));
        });
    });

    $(document).on("mouseleave", ".zeko-star-rating-input", function() {
        var val = parseInt($(this).find("input[name='rating']").val(), 10) || 0;
        $(this).find(".zeko-star-btn").each(function() {
            var v = parseInt($(this).data("value"), 10);
            $(this).find(".dashicons").attr("class", "dashicons dashicons-star" + (v <= val ? "" : "-empty"));
        });
    });

    $(document).on("submit", "#zeko-review-form", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === "undefined") return;

        var $form = $(this);
        var $status = $form.find(".zeko-review-status");
        var rating = parseInt($form.find("input[name='rating']").val(), 10);

        if (rating < 1 || rating > 5) {
            $status.addClass("zeko-inline-error").text("Please select a rating.").show();
            return;
        }

        var formData = new FormData($form[0]);
        formData.append("action", "zeko_job_submit_review");

        $status.hide();
        $form.find("button[type='submit']").prop("disabled", true);

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $status.addClass("zeko-inline-success").text(response.data.message).show();
                    $form.find("input[name='rating']").val(0);
                    $form.find(".zeko-star-btn .dashicons").attr("class", "dashicons dashicons-star-empty");
                    $form.find("textarea").val("");
                } else {
                    $status.addClass("zeko-inline-error").text(response.data.message || "Error").show();
                }
            },
            error: function() {
                $status.addClass("zeko-inline-error").text(zekoI18n().networkError || "Network error.").show();
            },
            complete: function() {
                $form.find("button[type='submit']").prop("disabled", false);
            }
        });
    });

    /* =============================================
       Map View — Leaflet / OpenStreetMap
       ============================================= */
    var zekoMap = null;
    var zekoMapMarkers = [];

    function zekoInitMap() {
        if (zekoMap || typeof L === 'undefined' || !$('.zeko-map-container').length) return;

        var center = (typeof zeko_map_data !== 'undefined' && zeko_map_data.map_center)
            ? zeko_map_data.map_center : [39.8283, -98.5795];
        var zoom = (typeof zeko_map_data !== 'undefined' && zeko_map_data.map_zoom)
            ? zeko_map_data.map_zoom : 4;

        zekoMap = L.map($('.zeko-map-container')[0], {
            scrollWheelZoom: false
        }).setView(center, zoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 18
        }).addTo(zekoMap);

        var jobs = (typeof zekoJobMapData !== 'undefined') ? zekoJobMapData : [];
        var bounds = [];

        jobs.forEach(function(job) {
            var marker = L.marker([job.lat, job.lng]).addTo(zekoMap);

            var salaryHtml = job.salary ? '<div class="zeko-map-salary">' + escapeHtml(job.salary) + '</div>' : '';
            var popupHtml = '<div class="zeko-map-popup">' +
                '<h4><a href="' + escapeHtml('/jobs/' + job.slug + '/') + '">' + escapeHtml(job.title) + '</a></h4>' +
                '<p class="zeko-map-popup-loc">' + escapeHtml(job.location) + '</p>' +
                '<span class="zeko-map-popup-type">' + escapeHtml(job.type) + '</span>' +
                salaryHtml +
                '</div>';

            marker.bindPopup(popupHtml);
            zekoMapMarkers.push(marker);
            bounds.push([job.lat, job.lng]);
        });

        if (bounds.length > 0) {
            zekoMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
        }
    }

    $(document).on('click', '.zeko-view-btn', function() {
        var $btn = $(this);
        var view = $btn.data('view');

        $('.zeko-view-btn').removeClass('active');
        $btn.addClass('active');
        localStorage.setItem("zeko-jobs-view", view);

        var $grid = $('.zeko-jobs-grid');
        if (view === 'map') {
            $grid.hide();
            $('.zeko-map-container').show();
            if (!zekoMap) {
                zekoInitMap();
            } else {
                zekoMap.invalidateSize();
            }
        } else {
            $('.zeko-map-container').hide();
            $grid.show();
            $grid.removeClass('zeko-view-mode-list zeko-view-mode-grid');
            if (view === 'list') {
                $grid.addClass('zeko-view-mode-list');
            }
        }
    });

    var savedView = localStorage.getItem("zeko-jobs-view");
    if (savedView === 'list' || savedView === 'map') {
        $('.zeko-view-btn[data-view="' + savedView + '"]').trigger('click');
    }

    /* =============================================
       Geolocation — Near Me
       ============================================= */
    $(document).on('click', '.zeko-near-me-btn', function() {
        var $btn = $(this);
        var $form = $btn.closest('form');
        var $lat = $form.find('.zeko-nearby-lat');
        var $lng = $form.find('.zeko-nearby-lng');
        var $radius = $form.find('.zeko-nearby-radius');

        if ($btn.hasClass('active')) {
            $btn.removeClass('active');
            $lat.val('');
            $lng.val('');
            $radius.hide();
            $btn.attr('aria-pressed', 'false');
            zekoAnnounce("Near Me filter removed.");
            $form.trigger('submit');
            return;
        }

        if (!navigator.geolocation) {
            alert(zeko_jobs_ajax.i18n.networkError || 'Geolocation is not supported by your browser.');
            return;
        }

        $btn.addClass('zeko-loading');
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                $btn.removeClass('zeko-loading').addClass('active');
                $btn.attr('aria-pressed', 'true');
                $lat.val(pos.coords.latitude.toFixed(7));
                $lng.val(pos.coords.longitude.toFixed(7));
                $radius.show();
                zekoAnnounce("Location found. Showing nearby jobs.");
                $form.trigger('submit');
            },
            function(err) {
                $btn.removeClass('zeko-loading');
                var msg = 'Unable to get your location.';
                if (err.code === 1) msg = 'Location access denied. Please allow location access and try again.';
                else if (err.code === 2) msg = 'Location unavailable. Please try again.';
                else if (err.code === 3) msg = 'Location request timed out. Please try again.';
                alert(msg);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 }
        );
    });

    $(document).on('change', '.zeko-nearby-radius', function() {
        var $form = $(this).closest('form');
        if ($form.find('.zeko-nearby-lat').val()) {
            $form.trigger('submit');
        }
    });

    /* =============================================
       Archive — Pagination Mode Toggle + Load More
       ============================================= */
    var $archiveGrid = $('.zeko-jobs-grid');
    if ($archiveGrid.length) {
        var zekoTotalPages = parseInt($archiveGrid.data('total-pages'), 10) || 1;
        var savedMode = localStorage.getItem('zeko-page-mode') || 'load-more';
        var $archiveWrap = $archiveGrid.closest('.zeko-jobs-archive');

        // Apply saved mode
        $archiveWrap.addClass('zeko-page-mode-' + savedMode);

        // Set active toggle button
        $('.zeko-page-mode-btn').removeClass('active');
        $('.zeko-page-mode-btn[data-mode="' + savedMode + '"]').addClass('active');

        // Toggle between modes
        $(document).on('click', '.zeko-page-mode-btn', function() {
            var mode = $(this).data('mode');
            localStorage.setItem('zeko-page-mode', mode);
            $archiveWrap.removeClass('zeko-page-mode-load-more zeko-page-mode-paginated').addClass('zeko-page-mode-' + mode);
            $('.zeko-page-mode-btn').removeClass('active');
            $(this).addClass('active');
        });

        // Load More AJAX
        var zekoLoadMorePage = 1;
        var zekoLoadMoreLoading = false;

        $(document).on('click', '.zeko-load-more-btn', function() {
            if (zekoLoadMoreLoading) return;
            zekoLoadMorePage++;
            zekoLoadMoreLoading = true;

            var $btn = $(this);
            var $spinner = $('.zeko-load-more-spinner');
            var $count = $('.zeko-load-more-count');
            $btn.hide();
            $spinner.show();

            var params = {
                action: 'zeko_job_load_more',
                nonce: zeko_jobs_ajax.dashboard_nonce,
                page: zekoLoadMorePage,
                job_search: $('input[name="job_search"]').val() || '',
                job_type: $('select[name="job_type"]').val() || '',
                job_location: $('input[name="job_location"]').val() || '',
                job_category: $('select[name="job_category"]').val() || '',
                job_industry: $('select[name="job_industry"]').val() || '',
                company: $('select[name="company"]').val() || '',
                salary_min: $('input[name="salary_min"]').val() || 0,
                salary_max: $('input[name="salary_max"]').val() || 0,
                experience_level: $('select[name="experience_level"]').val() || '',
                remote_option: $('select[name="remote_option"]').val() || '',
                date_range: $('select[name="date_range"]').val() || 0,
                sort: $('select[name="sort"]').val() || '',
                nearby_lat: $('input[name="nearby_lat"]').val() || 0,
                nearby_lng: $('input[name="nearby_lng"]').val() || 0,
                nearby_radius: $('select[name="nearby_radius"]').val() || 0
            };

            $.post(zeko_jobs_ajax.ajax_url, params, function(res) {
                zekoLoadMoreLoading = false;
                $spinner.hide();

                if (res.success && res.data.html) {
                    $archiveGrid.append(res.data.html);
                }

                var currentCount = $archiveGrid.find('.zeko-job-card').length;
                var totalCount = res.data.total || currentCount;
                $count.text(currentCount + ' of ' + totalCount + ' jobs');

                if (res.success && res.data.has_more) {
                    $btn.show();
                } else {
                    $btn.hide();
                    $count.text('All ' + totalCount + ' jobs loaded');
                }
            }).fail(function() {
                zekoLoadMoreLoading = false;
                zekoLoadMorePage--;
                $spinner.hide();
                $btn.show();
            });
        });

        // Infinite scroll — auto-trigger the load-more button when it scrolls
        // into view (only in load-more mode; the paginated mode is the fallback).
        if ("IntersectionObserver" in window && savedMode !== "paginated") {
            var zekoScrollObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting && !zekoLoadMoreLoading) {
                        var $lm = $(".zeko-load-more-btn");
                        if ($lm.is(":visible")) {
                            $lm.trigger("click");
                        }
                    }
                });
            }, { rootMargin: "200px" });

            var $observeTarget = $(".zeko-load-more-btn").get(0);
            if ($observeTarget) {
                zekoScrollObserver.observe($observeTarget);
            }
        }
    }

    /* =============================================
       Post Job Form — Duplicate Detection
       ============================================= */
    var duplicateCheckTimer;
    var duplicatesDismissed = false;

    function zekoCheckDuplicates() {
        if (duplicatesDismissed || typeof zeko_jobs_ajax === "undefined") return;
        var $form = $("#zeko-job-create-form");
        if (!$form.length) return;
        var title = $form.find("input[name=title]").val();
        var location = $form.find("input[name=location]").val();
        var type = $form.find("select[name=type]").val();
        if (!title || title.length < 5) return;
        var nonce = $form.find("input[name=zeko_job_create_nonce]").val();
        if (!nonce) return;
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_check_duplicates",
                nonce: nonce,
                title: title,
                location: location,
                type: type
            },
            success: function(response) {
                if (response.success && response.data.duplicates && response.data.duplicates.length > 0) {
                    var $warning = $("#zeko-duplicate-warning");
                    var $list = $("#zeko-duplicate-list");
                    $list.empty();
                    $.each(response.data.duplicates, function(i, job) {
                        var date = job.created_at ? new Date(job.created_at).toLocaleDateString() : "";
                        $list.append(
                            '<li><strong>' + $("<span>").text(job.title).html() + '</strong> &mdash; ' +
                            $("<span>").text(job.location || "").html() + ' (' + $("<span>").text(job.type || "").html() + ')' +
                            (date ? ' &mdash; ' + date : '') + '</li>'
                        );
                    });
                    $warning.slideDown();
                } else {
                    $("#zeko-duplicate-warning").slideUp();
                }
            }
        });
    }

    $("#zeko-job-create-form").on("input", "input[name=title], input[name=location], select[name=type]", function() {
        clearTimeout(duplicateCheckTimer);
        duplicateCheckTimer = setTimeout(zekoCheckDuplicates, 800);
    });

    $(document).on("click", "#zeko-dismiss-duplicates", function() {
        duplicatesDismissed = true;
        $("#zeko-duplicate-warning").slideUp();
    });

    /* =============================================
       One-Click Apply
       ============================================= */
    $(document).on("click", ".zeko-one-click-apply-btn", function(e) {
        e.preventDefault();
        if (typeof zeko_jobs_ajax === "undefined") return;
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var $nonce = $btn.closest(".job-actions").find(".zeko-job-apply-btn").first();
        var nonceVal = "";
        var $form = $btn.closest("article").find(".zeko-job-apply-form-wrapper .zeko-job-apply-form input[name=zeko_job_nonce]");
        if ($form.length) {
            nonceVal = $form.val();
        }
        if (!nonceVal) {
            nonceVal = typeof zeko_jobs_ajax.job_apply_nonce !== "undefined" ? zeko_jobs_ajax.job_apply_nonce : "";
        }
        if (!nonceVal) {
            alert("Security nonce not found. Please refresh the page.");
            return;
        }
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_one_click_apply",
                nonce: nonceVal,
                job_id: jobId
            },
            beforeSend: function() {
                $btn.prop("disabled", true).html('<span class="dashicons dashicons-update zeko-spin"></span>');
            },
            success: function(response) {
                if (response.success) {
                    $btn.html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);
                    $btn.css({ "background-color": "#46b450", "color": "#fff", "border-color": "#46b450" });
                } else {
                    $btn.html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);
                    $btn.css({ "background-color": "#dc3232", "color": "#fff", "border-color": "#dc3232" });
                    setTimeout(function() {
                        $btn.prop("disabled", false).html('<span class="dashicons dashicons-rocket" aria-hidden="true"></span> Quick Apply');
                        $btn.css({ "background-color": "", "color": "", "border-color": "" });
                    }, 3000);
                }
            },
            error: function() {
                $btn.prop("disabled", false).html('<span class="dashicons dashicons-rocket" aria-hidden="true"></span> Quick Apply');
                $btn.css({ "background-color": "", "color": "", "border-color": "" });
            }
        });
    });

    /* =============================================
       Auto-Response Form
       ============================================= */

    $(document).on("submit", "#zeko-auto-response-form", function(e) {
        e.preventDefault();
        var $form = $(this);
        var $msg = $("#zeko-auto-response-message");
        var data = $form.serialize() + "&action=zeko_job_save_auto_response";

        $form.find("button[type=submit]").prop("disabled", true);
        $msg.text("").removeClass("zeko-success zeko-error");

        $.post(zeko_jobs_ajax.ajax_url, data, function(response) {
            if (response.success) {
                $msg.text(response.data.message).addClass("zeko-success");
            } else {
                $msg.text(response.data.message).addClass("zeko-error");
            }
            $form.find("button[type=submit]").prop("disabled", false);
        });
    });

    /* =============================================
       Outreach Form
       ============================================= */

    $(document).on("submit", "#zeko-outreach-form", function(e) {
        e.preventDefault();
        var $form = $(this);
        var $msg = $("#zeko-outreach-message");
        var data = $form.serialize() + "&action=zeko_job_send_outreach";

        $form.find("button[type=submit]").prop("disabled", true);
        $msg.text("").removeClass("zeko-success zeko-error");

        $.post(zeko_jobs_ajax.ajax_url, data, function(response) {
            if (response.success) {
                $msg.text(response.data.message).addClass("zeko-success");
                $form.find("textarea[name=outreach_message]").val("");
            } else {
                $msg.text(response.data.message).addClass("zeko-error");
            }
            $form.find("button[type=submit]").prop("disabled", false);
        });
    });

    /* =============================================
       Seeker Application Filters & Sort
       ============================================= */

    function zekoFilterSeekerApps() {
        var status = $("#zeko-seeker-filter-status").val().toLowerCase();
        var sort   = $("#zeko-seeker-filter-sort").val();
        var $list  = $("#zeko-seeker-apps-list");
        var $cards = $list.children(".zeko-application-card");

        $cards.each(function() {
            var $card = $(this);
            var cardStatus = ($card.data("status") || "").toLowerCase();
            if (!status || cardStatus === status) {
                $card.show();
            } else {
                $card.hide();
            }
        });

        var $visible = $list.children(".zeko-application-card:visible");
        var sorted = $visible.detach().sort(function(a, b) {
            var $a = $(a), $b = $(b);
            if (sort === "newest") {
                return ($b.data("date") || "").localeCompare($a.data("date") || "");
            } else if (sort === "oldest") {
                return ($a.data("date") || "").localeCompare($b.data("date") || "");
            } else if (sort === "company") {
                return ($a.data("company") || "").localeCompare($b.data("company") || "");
            } else if (sort === "status") {
                return ($a.data("status") || "").localeCompare($b.data("status") || "");
            }
            return 0;
        });
        $list.append(sorted);
    }

    $(document).on("change", "#zeko-seeker-filter-status, #zeko-seeker-filter-sort", zekoFilterSeekerApps);

    /* =============================================
       Save/Unsave Job Toggle
       ============================================= */

    $(document).on("click", ".zeko-save-job-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var isSaved = $btn.data("saved");

        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_toggle_save",
            job_id: jobId,
            zeko_job_nonce: $btn.data("nonce")
        }, function(response) {
            if (response.success) {
                $btn.data("saved", response.data.saved);
                if (response.data.saved) {
                    $btn.addClass("zeko-saved").html('<span class="dashicons dashicons-heart-filled" aria-hidden="true"></span> Saved');
                    zekoAnnounce("Job saved to bookmarks.");
                } else {
                    $btn.removeClass("zeko-saved").html('<span class="dashicons dashicons-heart" aria-hidden="true"></span> Save');
                    zekoAnnounce("Job removed from bookmarks.");
                    if ($btn.closest(".zeko-saved-job-card").length) {
                        $btn.closest(".zeko-saved-job-card").fadeOut(300, function() { $(this).remove(); });
                    }
                }
            }
        });
    });

    /* =============================================
       Seeker Profile Form
       ============================================= */

    $(document).on("submit", "#zeko-seeker-profile-form", function(e) {
        e.preventDefault();
        var $form = $(this);
        var $msg = $("#zeko-profile-message");
        var data = $form.serialize() + "&action=zeko_job_save_seeker_profile";

        $form.find("button[type=submit]").prop("disabled", true);
        $msg.text("").removeClass("zeko-success zeko-error");

        $.post(zeko_jobs_ajax.ajax_url, data, function(response) {
            if (response.success) {
                $msg.text(response.data.message).addClass("zeko-success");
            } else {
                $msg.text(response.data.message).addClass("zeko-error");
            }
            $form.find("button[type=submit]").prop("disabled", false);
        });
    });

    $(document).on("click", "#zeko-add-experience", function() {
        var tpl = '<div class="zeko-exp-row">' +
            '<input type="text" name="exp_company[]" placeholder="Company">' +
            '<input type="text" name="exp_role[]" placeholder="Role">' +
            '<input type="text" name="exp_dates[]" placeholder="Dates (e.g. 2020 - 2023)">' +
            '<input type="text" name="exp_desc[]" placeholder="Description">' +
            '<button type="button" class="button button-small zeko-remove-exp">Remove</button>' +
            '</div>';
        $("#zeko-experience-list").append(tpl);
    });

    $(document).on("click", ".zeko-remove-exp", function() {
        $(this).closest(".zeko-exp-row").remove();
    });

    $(document).on("click", "#zeko-add-education", function() {
        var tpl = '<div class="zeko-edu-row">' +
            '<input type="text" name="edu_institution[]" placeholder="Institution">' +
            '<input type="text" name="edu_degree[]" placeholder="Degree">' +
            '<input type="text" name="edu_dates[]" placeholder="Dates (e.g. 2016 - 2020)">' +
            '<button type="button" class="button button-small zeko-remove-edu">Remove</button>' +
            '</div>';
        $("#zeko-education-list").append(tpl);
    });

    $(document).on("click", ".zeko-remove-edu", function() {
        $(this).closest(".zeko-edu-row").remove();
    });

    /* =============================================
       Employer Analytics — Per-Job Panel
       ============================================= */
    var analyticsChart = null;

    function renderAnalyticsChart(timeSeries) {
        var ctx = document.getElementById("zeko-analytics-chart");
        if (!ctx) return;
        if (analyticsChart) { analyticsChart.destroy(); }

        var labels = timeSeries.map(function(r) {
            var d = new Date(r.date);
            return (d.getMonth() + 1) + "/" + d.getDate();
        });
        var viewsData = timeSeries.map(function(r) { return parseInt(r.views) || 0; });
        var uniqueData = timeSeries.map(function(r) { return parseInt(r.unique_visitors) || 0; });

        analyticsChart = new Chart(ctx.getContext("2d"), {
            type: "line",
            data: {
                labels: labels,
                datasets: [
                    {
                        label: "Views",
                        data: viewsData,
                        borderColor: "#6366f1",
                        backgroundColor: "rgba(99,102,241,0.1)",
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 2
                    },
                    {
                        label: "Unique Visitors",
                        data: uniqueData,
                        borderColor: "#10b981",
                        backgroundColor: "rgba(16,185,129,0.1)",
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: "top" } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { ticks: { maxTicksLimit: 14 } }
                }
            }
        });
    }

    function renderComparison(data) {
        var $el = $("#zeko-analytics-comparison");
        if (!data || !data.current || !data.previous) { $el.html(""); return; }
        var metrics = [
            { key: "views", label: "Views" },
            { key: "unique_visitors", label: "Unique Visitors" },
            { key: "applications", label: "Applications" }
        ];
        var html = '<div class="zeko-comparison-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">';
        metrics.forEach(function(m) {
            var cur = data.current[m.key] || 0;
            var prev = data.previous[m.key] || 0;
            var pct = prev > 0 ? Math.round(((cur - prev) / prev) * 100) : (cur > 0 ? 100 : 0);
            var cls = pct > 0 ? "zeko-trend-up" : (pct < 0 ? "zeko-trend-down" : "");
            var arrow = pct > 0 ? "&#9650;" : (pct < 0 ? "&#9660;" : "");
            html += '<div class="zeko-comparison-card" style="text-align:center;">';
            html += '<div style="font-size:13px;color:var(--color-text-secondary,#718096);">' + m.label + '</div>';
            html += '<div style="font-size:20px;font-weight:700;">' + cur + '</div>';
            html += '<div class="' + cls + '" style="font-size:12px;">' + arrow + ' ' + Math.abs(pct) + '% vs prev</div>';
            html += '</div>';
        });
        html += '</div>';
        $el.html(html);
    }

    function renderStatusBreakdown(breakdown) {
        var $el = $("#zeko-analytics-status-breakdown");
        if (!breakdown) { $el.html(""); return; }
        var total = Object.values(breakdown).reduce(function(a, b) { return a + b; }, 0);
        if (total === 0) { $el.html('<p style="color:var(--color-text-secondary);">No applications yet.</p>'); return; }
        var statuses = [
            { key: "applied", label: "Applied", color: "#6366f1" },
            { key: "reviewed", label: "Reviewed", color: "#f59e0b" },
            { key: "interviewing", label: "Interviewing", color: "#3b82f6" },
            { key: "hired", label: "Hired", color: "#10b981" },
            { key: "rejected", label: "Rejected", color: "#ef4444" }
        ];
        var html = '<div style="display:flex;flex-wrap:wrap;gap:12px;">';
        statuses.forEach(function(s) {
            var count = breakdown[s.key] || 0;
            if (count > 0) {
                var pct = Math.round((count / total) * 100);
                html += '<div style="flex:1;min-width:100px;text-align:center;">';
                html += '<div style="width:12px;height:12px;border-radius:50%;background:' + s.color + ';display:inline-block;margin-right:4px;"></div>';
                html += '<strong>' + count + '</strong> ' + s.label + ' <span style="color:var(--color-text-secondary);">(' + pct + '%)</span>';
                html += '</div>';
            }
        });
        html += '</div>';
        $el.html(html);
    }

    $(document).on("click", ".zeko-job-analytics-btn", function() {
        var $btn = $(this);
        var jobId = $btn.data("job-id");
        var nonce = $btn.data("nonce");
        var $panel = $("#zeko-job-analytics-panel");
        var days = $("#zeko-analytics-period").val() || 30;

        if (typeof zeko_jobs_ajax === "undefined") return;

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_get_analytics",
                nonce: nonce,
                job_id: jobId,
                days: days
            },
            beforeSend: function() {
                $btn.prop("disabled", true);
                $panel.slideDown(200);
                $panel.find(".zeko-analytics-number").text("...");
            },
            success: function(response) {
                if (response.success && response.data.analytics) {
                    var a = response.data.analytics;
                    var ts = response.data.time_series || [];
                    var comp = response.data.comparison;

                    $("#zeko-analytics-job-title").text(a.job ? a.job.title : "Job Analytics");
                    $("#zeko-analytics-total-views").text(a.total_views || 0);
                    $("#zeko-analytics-unique-visitors").text(a.unique_visitors || 0);
                    $("#zeko-analytics-total-apps").text(a.total_applications || 0);
                    $("#zeko-analytics-conversion").text((a.conversion_rate || 0) + "%");

                    renderAnalyticsChart(ts);
                    renderComparison(comp);
                    renderStatusBreakdown(a.status_breakdown);

                    $panel.data("job-id", jobId);
                    $("html, body").animate({ scrollTop: $panel.offset().top - 100 }, 300);
                }
            },
            complete: function() {
                $btn.prop("disabled", false);
            }
        });
    });

    $(document).on("change", "#zeko-analytics-period", function() {
        var $panel = $("#zeko-job-analytics-panel");
        var jobId = $panel.data("job-id");
        if (!jobId) return;
        $(".zeko-job-analytics-btn[data-job-id='" + jobId + "']").trigger("click");
    });

    $(document).on("click", "#zeko-close-analytics", function() {
        $("#zeko-job-analytics-panel").slideUp(200);
    });

    $(document).on("click", "#zeko-export-analytics", function() {
        var $panel = $("#zeko-job-analytics-panel");
        var jobId = $panel.data("job-id");
        var days = $("#zeko-analytics-period").val() || 30;
        if (!jobId || typeof zeko_jobs_ajax === "undefined") return;

        var $form = $("<form>", { method: "POST", action: zeko_jobs_ajax.ajax_url, style: "display:none;" });
        $form.append($("<input>", { type: "hidden", name: "action", value: "zeko_job_export_analytics" }));
        $form.append($("<input>", { type: "hidden", name: "nonce", value: $(".zeko-job-analytics-btn[data-job-id='" + jobId + "']").data("nonce") }));
        $form.append($("<input>", { type: "hidden", name: "job_id", value: jobId }));
        $form.append($("<input>", { type: "hidden", name: "days", value: days }));
        $("body").append($form);
        $form.submit().remove();
    });

    /* =============================================
       Candidate Comparison
       ============================================= */
    var compareList = [];

    $(document).on("click", ".zeko-compare-candidate-btn", function(e) {
        e.preventDefault();
        e.stopPropagation();
        var appId = $(this).data("app-id");
        var idx = compareList.indexOf(appId);
        if (idx > -1) {
            compareList.splice(idx, 1);
            $(this).removeClass("zeko-compare-active");
        } else {
            if (compareList.length >= 3) {
                zekoShowMessage($("body"), "You can compare up to 3 candidates. Remove one first.", "error");
                return;
            }
            compareList.push(appId);
            $(this).addClass("zeko-compare-active");
        }
        // Update button text
        $(".zeko-compare-candidate-btn").each(function() {
            var id = $(this).data("app-id");
            if (compareList.indexOf(id) > -1) {
                $(this).addClass("zeko-compare-active");
            } else {
                $(this).removeClass("zeko-compare-active");
            }
        });
        // Show floating compare button
        if (compareList.length >= 2) {
            if (!$("#zeko-compare-float-btn").length) {
                $("body").append('<button id="zeko-compare-float-btn" class="button button-primary" style="position:fixed;bottom:24px;right:24px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.2);">Compare (' + compareList.length + ')</button>');
            } else {
                $("#zeko-compare-float-btn").text("Compare (" + compareList.length + ")");
            }
        } else {
            $("#zeko-compare-float-btn").remove();
        }
    });

    $(document).on("click", "#zeko-compare-float-btn", function() {
        if (compareList.length < 2 || typeof zeko_jobs_ajax === "undefined") return;
        var $modal = $("#zeko-compare-modal");
        var $grid  = $("#zeko-compare-grid");
        var $empty = $("#zeko-compare-empty");
        $grid.html("").show();
        $empty.hide();
        $modal.show();
        $grid.append('<div class="zeko-compare-loading">Loading...</div>');

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "GET",
            data: {
                action: "zeko_job_compare_candidates",
                nonce: zeko_jobs_ajax.nonce,
                app_ids: compareList
            },
            success: function(response) {
                $grid.find(".zeko-compare-loading").remove();
                if (response.success && response.data.candidates.length > 0) {
                    var candidates = response.data.candidates;
                    // Build table
                    var html = '<table class="zeko-compare-table"><thead><tr><th></th>';
                    candidates.forEach(function(c) {
                        html += '<th>' + escapeHtml(c.seeker_name) + (c.open_to_work ? ' <span class="zeko-badge zeko-badge-success" style="font-size:10px;">OTW</span>' : '') + '</th>';
                    });
                    html += '</tr></thead><tbody>';
                    // Status
                    html += '<tr><td><strong>Status</strong></td>';
                    candidates.forEach(function(c) { html += '<td><span class="zeko-status-badge zeko-status-' + c.status + '">' + c.status.replace(/_/g, " ").replace(/\b\w/g, function(l){return l.toUpperCase()}) + '</span></td>'; });
                    html += '</tr>';
                    // Applied
                    html += '<tr><td><strong>Applied</strong></td>';
                    candidates.forEach(function(c) { html += '<td>' + (c.applied_at ? new Date(c.applied_at).toLocaleDateString() : '-') + '</td>'; });
                    html += '</tr>';
                    // Rating
                    html += '<tr><td><strong>Rating</strong></td>';
                    candidates.forEach(function(c) {
                        var stars = '';
                        for (var i = 1; i <= 5; i++) stars += '<span class="dashicons dashicons-star' + (i <= c.rating ? '' : '-empty') + '"></span>';
                        html += '<td>' + stars + '</td>';
                    });
                    html += '</tr>';
                    // Location
                    html += '<tr><td><strong>Location</strong></td>';
                    candidates.forEach(function(c) { html += '<td>' + escapeHtml(c.location || '-') + '</td>'; });
                    html += '</tr>';
                    // Skills
                    html += '<tr><td><strong>Skills</strong></td>';
                    candidates.forEach(function(c) {
                        var skills = (c.skills || []).map(function(s) { return '<span class="zeko-alert-tag">' + escapeHtml(s) + '</span>'; }).join(' ');
                        html += '<td>' + (skills || '-') + '</td>';
                    });
                    html += '</tr>';
                    // Experience
                    html += '<tr><td><strong>Experience</strong></td>';
                    candidates.forEach(function(c) {
                        var exp = (c.experience || []).map(function(e) { return '<strong>' + escapeHtml(e.role || '') + '</strong> at ' + escapeHtml(e.company || '') + '<br><small>' + escapeHtml(e.dates || '') + '</small>'; }).join('<br>');
                        html += '<td>' + (exp || '-') + '</td>';
                    });
                    html += '</tr>';
                    // Education
                    html += '<tr><td><strong>Education</strong></td>';
                    candidates.forEach(function(c) {
                        var edu = (c.education || []).map(function(e) { return escapeHtml(e.degree || '') + '<br>' + escapeHtml(e.institution || '') + '<br><small>' + escapeHtml(e.dates || '') + '</small>'; }).join('<br>');
                        html += '<td>' + (edu || '-') + '</td>';
                    });
                    html += '</tr>';
                    // Cover Letter
                    html += '<tr><td><strong>Cover Letter</strong></td>';
                    candidates.forEach(function(c) {
                        html += '<td>' + (c.cover_letter ? '<p style="max-height:120px;overflow-y:auto;font-size:13px;">' + escapeHtml(c.cover_letter.substring(0, 500)) + (c.cover_letter.length > 500 ? '...' : '') + '</p>' : '<em>No cover letter</em>') + '</td>';
                    });
                    html += '</tr>';
                    // Resume
                    html += '<tr><td><strong>Resume</strong></td>';
                    candidates.forEach(function(c) {
                        html += '<td>' + (c.resume_url ? '<a href="' + c.resume_url + '" target="_blank" rel="noopener">View Resume</a>' : '-') + '</td>';
                    });
                    html += '</tr>';
                    html += '</tbody></table>';
                    $grid.html(html);
                } else {
                    $empty.show();
                    $grid.hide();
                }
            },
            error: function() {
                $grid.find(".zeko-compare-loading").text("Error loading candidates.");
            }
        });
    });

    /* =============================================
       Interview Feedback
       ============================================= */
    $(document).on("click", ".zeko-interview-feedback-btn", function(e) {
        e.preventDefault();
        var interviewId = $(this).data("interview-id");
        var $modal = $("#zeko-app-detail-modal");
        // Load feedback form into a separate area
        var $feedbackArea = $(".zeko-interview-feedback-area");
        if (!$feedbackArea.length) {
            $feedbackArea = $('<div class="zeko-interview-feedback-area" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--color-border);"><h5>Interview Feedback</h5></div>');
            $modal.find(".zeko-modal-body").append($feedbackArea);
        }
        $feedbackArea.show().html('<p>Loading...</p>');

        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "GET",
            data: { action: "zeko_job_get_interview_feedback", nonce: zeko_jobs_ajax.nonce, interview_id: interviewId },
            success: function(response) {
                if (response.success) {
                    var fb = response.data.feedback || {};
                    var html = '<form class="zeko-feedback-form zeko-form">';
                    html += '<input type="hidden" name="interview_id" value="' + interviewId + '">';
                    // Rating
                    html += '<div class="zeko-field"><label>Overall Rating</label><div class="zeko-star-rating" data-rating="' + (fb.rating || 0) + '">';
                    for (var i = 1; i <= 5; i++) html += '<span class="dashicons dashicons-star' + (i <= (fb.rating || 0) ? '' : '-empty') + ' zeko-star" data-value="' + i + '"></span>';
                    html += '<input type="hidden" name="feedback_rating" value="' + (fb.rating || 0) + '">';
                    html += '</div></div>';
                    // Recommend
                    html += '<div class="zeko-field"><label>Recommendation</label><select name="feedback_recommend"><option value="">-- Select --</option><option value="strong_yes"' + (fb.recommend === 'strong_yes' ? ' selected' : '') + '>Strong Yes</option><option value="yes"' + (fb.recommend === 'yes' ? ' selected' : '') + '>Yes</option><option value="neutral"' + (fb.recommend === 'neutral' ? ' selected' : '') + '>Neutral</option><option value="no"' + (fb.recommend === 'no' ? ' selected' : '') + '>No</option><option value="strong_no"' + (fb.recommend === 'strong_no' ? ' selected' : '') + '>Strong No</option></select></div>';
                    // Strengths
                    html += '<div class="zeko-field"><label>Strengths</label><textarea name="feedback_strengths" rows="3" placeholder="Key strengths observed...">' + escapeHtml(fb.strengths || '') + '</textarea></div>';
                    // Weaknesses
                    html += '<div class="zeko-field"><label>Areas for Improvement</label><textarea name="feedback_weaknesses" rows="3" placeholder="Areas to improve...">' + escapeHtml(fb.weaknesses || '') + '</textarea></div>';
                    // Notes
                    html += '<div class="zeko-field"><label>Additional Notes</label><textarea name="feedback_notes" rows="3" placeholder="Overall impressions...">' + escapeHtml(fb.notes || '') + '</textarea></div>';
                    html += '<div class="zeko-field"><button type="submit" class="button button-primary">Save Feedback</button></div>';
                    html += '</form>';
                    $feedbackArea.html(html);
                }
            }
        });
    });

    $(document).on("click", ".zeko-feedback-form button[type='submit']", function(e) {
        e.preventDefault();
        var $form = $(this).closest(".zeko-feedback-form");
        var data = $form.serialize() + "&action=zeko_job_save_interview_feedback&nonce=" + zeko_jobs_ajax.nonce;
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: data,
            success: function(response) {
                if (response.success) {
                    zekoShowMessage($form, response.data.message || "Feedback saved.", "success");
                } else {
                    zekoShowMessage($form, response.data.message || "Could not save.", "error");
                }
            }
        });
    });

    // Star rating in feedback form
    $(document).on("click", ".zeko-feedback-form .zeko-star", function() {
        var val = $(this).data("value");
        var $rating = $(this).closest(".zeko-star-rating");
        $rating.data("rating", val).find("input[name='feedback_rating']").val(val);
        $rating.find(".zeko-star").each(function() {
            var v = $(this).data("value");
            $(this).removeClass("dashicons-star-empty dashicons-star-filled").addClass(v <= val ? "dashicons-star-filled" : "dashicons-star-empty");
        });
    });

    /* ========================================
     * Pipeline Stages Management
     * ======================================== */
    var currentPipelineStages = [];

    function renderPipelineStages(stages) {
        var $list = $("#zeko-pipeline-stages-list");
        $list.empty();
        currentPipelineStages = [];
        var i = 0;
        for (var slug in stages) {
            if (!stages.hasOwnProperty(slug)) continue;
            currentPipelineStages.push({ slug: slug, label: stages[slug] });
            var $item = $(
                '<div class="zeko-pipeline-stage-item" data-index="' + i + '" data-slug="' + slug + '">' +
                    '<span class="dashicons dashicons-move zeko-stage-handle" style="cursor:move;color:var(--color-text-muted);margin-right:8px;"></span>' +
                    '<input type="text" class="zeko-stage-label-input" value="' + $('<span>').text(stages[slug]).html() + '" style="flex:1;">' +
                    '<span class="zeko-stage-slug-display" style="color:var(--color-text-muted);font-size:12px;margin:0 8px;">' + $('<span>').text(slug).html() + '</span>' +
                    '<button type="button" class="button-link zeko-remove-stage-btn" title="Remove"><span class="dashicons dashicons-trash" style="color:#dc3545;"></span></button>' +
                '</div>'
            );
            $list.append($item);
            i++;
        }
        if (typeof Sortable !== "undefined") {
            Sortable.create($list[0], { handle: ".zeko-stage-handle", animation: 150 });
        }
    }

    $(document).on("click", "#zeko-manage-stages-btn", function(e) {
        e.preventDefault();
        var $modal = $("#zeko-pipeline-modal");
        if (typeof zeko_jobs_ajax === "undefined") return;
        $.post(zeko_jobs_ajax.ajax_url, { action: "zeko_job_get_pipeline_stages", nonce: zeko_jobs_ajax.nonce }, function(r) {
            if (r.success && r.data.stages) {
                renderPipelineStages(r.data.stages);
                $modal.show();
            }
        });
    });

    $(document).on("click", ".zeko-modal-close, .zeko-pipeline-cancel-btn", function(e) {
        e.preventDefault();
        $(this).closest(".zeko-modal-overlay").hide();
    });

    $(document).on("click", "#zeko-add-stage-btn", function(e) {
        e.preventDefault();
        var slug  = $.trim($("#zeko-new-stage-slug").val()).toLowerCase().replace(/[^a-z0-9_-]/g, "-").replace(/-+/g, "-");
        var label = $.trim($("#zeko-new-stage-label").val());
        if (!slug || !label) return;
        currentPipelineStages.push({ slug: slug, label: label });
        renderPipelineStages(currentPipelineStages.reduce(function(o, s) { o[s.slug] = s.label; return o; }, {}));
        $("#zeko-new-stage-slug").val("");
        $("#zeko-new-stage-label").val("");
    });

    $(document).on("click", ".zeko-remove-stage-btn", function(e) {
        e.preventDefault();
        var $item = $(this).closest(".zeko-pipeline-stage-item");
        var idx   = parseInt($item.data("index"), 10);
        currentPipelineStages.splice(idx, 1);
        renderPipelineStages(currentPipelineStages.reduce(function(o, s) { o[s.slug] = s.label; return o; }, {}));
    });

    $(document).on("change", ".zeko-stage-label-input", function() {
        var $item = $(this).closest(".zeko-pipeline-stage-item");
        var idx   = parseInt($item.data("index"), 10);
        if (currentPipelineStages[idx]) {
            currentPipelineStages[idx].label = $(this).val();
        }
    });

    $(document).on("click", "#zeko-save-pipeline-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        if (typeof zeko_jobs_ajax === "undefined") return;
        // Collect latest labels from inputs.
        $(".zeko-pipeline-stage-item").each(function() {
            var idx = parseInt($(this).data("index"), 10);
            if (currentPipelineStages[idx]) {
                currentPipelineStages[idx].label = $(this).find(".zeko-stage-label-input").val();
            }
        });
        if (currentPipelineStages.length < 2) {
            alert(zeko_jobs_ajax.i18n && zeko_jobs_ajax.i18n.at_least_2_stages ? zeko_jobs_ajax.i18n.at_least_2_stages : "At least 2 stages required.");
            return;
        }
        $btn.prop("disabled", true).text("Saving…");
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_save_pipeline_stages",
            nonce: zeko_jobs_ajax.nonce,
            stages: currentPipelineStages
        }, function(r) {
            $btn.prop("disabled", false).text("Save Stages");
            if (r.success) {
                $("#zeko-pipeline-modal").hide();
                location.reload();
            } else {
                alert(r.data && r.data.message ? r.data.message : "Error saving stages.");
            }
        });
    });

    /* ========================================
     * Schedule Interview Modal
     * ======================================== */
    $(document).on("click", ".zeko-schedule-interview-btn", function(e) {
        e.preventDefault();
        var appId  = $(this).data("app-id");
        var seeker = $(this).data("seeker") || "";
        var job    = $(this).data("job") || "";
        $("#zeko-schedule-app-id").val(appId);
        $("#zeko-schedule-interview-info").text("Scheduling interview for " + seeker + " — " + job);
        // Set default datetime to tomorrow 10:00.
        var tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(10, 0, 0, 0);
        var pad = function(n) { return n < 10 ? "0" + n : n; };
        var defaultDT = tomorrow.getFullYear() + "-" + pad(tomorrow.getMonth() + 1) + "-" + pad(tomorrow.getDate()) + "T10:00";
        $("#zeko-schedule-interview-form").find("input[name='scheduled_at']").val(defaultDT);
        $("#zeko-schedule-interview-form").find("textarea[name='notes']").val("");
        $("#zeko-schedule-interview-form").find("input[name='location']").val("");
        $("#zeko-schedule-interview-modal").show();
    });

    $(document).on("click", "#zeko-confirm-schedule-btn", function(e) {
        e.preventDefault();
        var $btn    = $(this);
        var $form   = $("#zeko-schedule-interview-form");
        var appId   = $form.find("input[name='application_id']").val();
        var schedAt = $form.find("input[name='scheduled_at']").val();
        if (!appId || !schedAt) {
            alert("Please select a date and time.");
            return;
        }
        if (typeof zeko_jobs_ajax === "undefined") return;
        $btn.prop("disabled", true).text("Scheduling…");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: $form.serialize() + "&action=zeko_job_schedule_interview&nonce=" + zeko_jobs_ajax.nonce,
            success: function(r) {
                $btn.prop("disabled", false).html('<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> Schedule');
                if (r.success) {
                    $("#zeko-schedule-interview-modal").hide();
                    alert(r.data.message || "Interview scheduled.");
                    location.reload();
                } else {
                    alert(r.data && r.data.message ? r.data.message : "Error scheduling interview.");
                }
            },
            error: function() {
                $btn.prop("disabled", false).html('<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> Schedule');
                alert("Network error.");
            }
        });
    });

    /* =============================================
       Interview — Propose Times (Seeker)
       ============================================= */
    $(document).on("click", ".zeko-propose-times-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var interviewId = $btn.data("interview-id");
        var nonce = $btn.data("nonce");

        var timesHtml = '<div class="zeko-modal-overlay" id="zeko-propose-times-modal" style="display:flex;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;">' +
            '<div class="zeko-modal" style="background:#fff;border-radius:12px;padding:24px;max-width:420px;width:90%;">' +
            '<h3 style="margin:0 0 16px;">Propose Interview Times</h3>' +
            '<p style="font-size:13px;color:#666;margin-bottom:12px;">Suggest at least 2 time slots. The employer will pick one.</p>' +
            '<div class="zeko-propose-times-list">' +
            '<div style="margin-bottom:8px;"><input type="datetime-local" class="zeko-proposed-time-input" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;"></div>' +
            '<div style="margin-bottom:8px;"><input type="datetime-local" class="zeko-proposed-time-input" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;"></div>' +
            '<div style="margin-bottom:8px;"><input type="datetime-local" class="zeko-proposed-time-input" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;"></div>' +
            '</div>' +
            '<div style="display:flex;gap:8px;margin-top:16px;">' +
            '<button type="button" class="button button-primary zeko-submit-proposed-times" data-interview-id="' + interviewId + '" data-nonce="' + nonce + '">Submit</button>' +
            '<button type="button" class="button zeko-cancel-propose-times">Cancel</button>' +
            '</div></div></div>';
        $("body").append(timesHtml);
    });

    $(document).on("click", ".zeko-cancel-propose-times", function() {
        $("#zeko-propose-times-modal").remove();
    });

    $(document).on("click", ".zeko-submit-proposed-times", function() {
        var $btn = $(this);
        var interviewId = $btn.data("interview-id");
        var nonce = $btn.data("nonce");
        var times = [];
        $(".zeko-proposed-time-input").each(function() {
            var val = $(this).val();
            if (val) times.push(val);
        });

        if (times.length < 2) {
            alert("Please provide at least 2 time slots.");
            return;
        }

        $btn.prop("disabled", true);
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_propose_times",
                nonce: nonce,
                interview_id: interviewId,
                times: times
            },
            success: function(r) {
                if (r.success) {
                    $("#zeko-propose-times-modal").remove();
                    zekoAnnounce("Time slots proposed to employer.");
                    location.reload();
                } else {
                    alert(r.data && r.data.message ? r.data.message : "Error proposing times.");
                    $btn.prop("disabled", false);
                }
            },
            error: function() {
                $btn.prop("disabled", false);
                alert("Network error.");
            }
        });
    });

    /* =============================================
       Interview — Accept Proposed Time (Employer)
       ============================================= */
    $(document).on("click", ".zeko-accept-time-btn", function(e) {
        e.preventDefault();
        var $btn = $(this);
        var interviewId = $btn.data("interview-id");
        var acceptedTime = $btn.data("time");
        var nonce = $btn.data("nonce");

        if (!confirm("Confirm this interview time?")) return;

        $btn.prop("disabled", true);
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: {
                action: "zeko_job_accept_time",
                nonce: nonce,
                interview_id: interviewId,
                accepted_time: acceptedTime
            },
            success: function(r) {
                if (r.success) {
                    zekoAnnounce("Interview time confirmed.");
                    location.reload();
                } else {
                    alert(r.data && r.data.message ? r.data.message : "Error confirming time.");
                    $btn.prop("disabled", false);
                }
            },
            error: function() {
                $btn.prop("disabled", false);
                alert("Network error.");
            }
        });
    });

    /* =============================================
       CSV Job Import
       ============================================= */
    $(document).on("change", "#zeko-csv-import-input", function() {
        var file = this.files[0];
        if (!file) return;
        var $status = $("#zeko-csv-import-status");
        var formData = new FormData();
        formData.append("zeko_csv_file", file);
        formData.append("action", "zeko_job_import_csv");
        formData.append("nonce", zeko_jobs_ajax.nonce);

        $status.text("Importing...");
        $.ajax({
            url: zeko_jobs_ajax.ajax_url,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(r) {
                if (r.success) {
                    $status.text(r.data.message);
                    zekoAnnounce(r.data.message);
                    if (r.data.imported > 0) location.reload();
                } else {
                    $status.text(r.data.message || "Import failed.");
                }
            },
            error: function() {
                $status.text("Network error.");
            }
        });
        $(this).val("");
    });

    /* =============================================
       Applicant Comparison
       ============================================= */
    $(document).on("change", ".zeko-compare-select", function() {
        var $list = $(this).closest(".zeko-applications-list");
        var count = $list.find(".zeko-compare-select:checked").length;
        var $btn = $(".zeko-compare-apps-btn");
        if (count >= 2) {
            $btn.show().find("span:last").text("Compare Selected (" + count + ")");
        } else {
            $btn.hide();
        }
    });

    $(document).on("click", ".zeko-compare-apps-btn", function() {
        var $btn = $(this);
        var ids = [];
        $(".zeko-compare-select:checked").each(function() {
            ids.push($(this).data("seeker-id"));
        });
        if (ids.length < 2) return;

        var $overlay = $('<div class="zeko-modal-overlay" style="display:flex;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;"></div>');
        var $modal = $('<div class="zeko-modal zeko-compare-modal" style="background:#fff;border-radius:12px;max-width:95vw;max-height:90vh;overflow:auto;padding:24px;width:900px;"></div>');
        $modal.html('<h3 style="margin:0 0 16px;">Comparing Applicants...</h3><div class="zeko-compare-loading" style="text-align:center;padding:40px;"><span class="spinner is-active"></span></div>');
        $overlay.append($modal);
        $("body").append($overlay);

        $overlay.on("click", function(e) {
            if (e.target === this) $(this).remove();
        });

        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_compare_apps",
            nonce: $btn.data("nonce"),
            seeker_ids: ids
        }, function(r) {
            if (!r.success) {
                $modal.html('<h3 style="margin:0 0 16px;">Error</h3><p>' + (r.data.message || "Failed.") + '</p><button class="button zeko-close-compare">Close</button>');
                return;
            }
            var apps = r.data.applicants;
            var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;"><h3 style="margin:0;">Applicant Comparison</h3><button class="button button-small zeko-close-compare">&times;</button></div>';
            html += '<div class="zeko-compare-table" style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">';
            var fields = ["Profile", "Location", "Experience", "Skills", "Education", "Applications", "Profile Complete", "Open to Work"];
            fields.forEach(function(f) {
                html += '<tr><td style="padding:8px 12px;font-weight:600;background:var(--color-bg-secondary,#f7f7f7);border:1px solid var(--color-border,#e0e0e0);white-space:nowrap;width:140px;">' + f + '</td>';
                apps.forEach(function(a) {
                    var val = "";
                    if (f === "Profile") val = '<div style="display:flex;align-items:center;gap:8px;"><img src="' + a.avatar + '" style="width:32px;height:32px;border-radius:50%;">' + a.name + '</div>';
                    else if (f === "Location") val = a.location || "—";
                    else if (f === "Experience") val = a.total_years + " years";
                    else if (f === "Skills") val = a.skills.length ? a.skills.join(", ") : "—";
                    else if (f === "Education") {
                        val = a.education.length ? a.education.map(function(e){ return (e.degree||"") + (e.field ? " in " + e.field : ""); }).join(", ") : "—";
                    }
                    else if (f === "Applications") val = a.app_count + " (" + a.hired_count + " hired)";
                    else if (f === "Profile Complete") val = '<div style="display:flex;align-items:center;gap:6px;"><div style="width:60px;height:6px;background:#e0e0e0;border-radius:3px;"><div style="width:' + a.profile_pct + '%;height:100%;background:' + (a.profile_pct >= 80 ? '#22c55e' : a.profile_pct >= 50 ? '#f59e0b' : '#ef4444') + ';border-radius:3px;"></div></div>' + a.profile_pct + '%</div>';
                    else if (f === "Open to Work") val = a.open_to_work ? '<span class="dashicons dashicons-yes" style="color:#22c55e;"></span>' : '<span class="dashicons dashicons-no-alt" style="color:#999;"></span>';
                    html += '<td style="padding:8px 12px;border:1px solid var(--color-border,#e0e0e0);vertical-align:top;">' + val + '</td>';
                });
                html += '</tr>';
            });
            html += '</table></div>';
            $modal.html(html);
        }).fail(function() {
            $modal.html('<h3 style="margin:0 0 16px;">Network Error</h3><button class="button zeko-close-compare">Close</button>');
        });

        $(document).on("click", ".zeko-close-compare", function() {
            $(".zeko-compare-modal, .zeko-modal-overlay").remove();
        });
    });

    /* =============================================
       LinkedIn Integration
       ============================================= */
    $(document).on("click", ".zeko-linkedin-connect-btn", function() {
        var $btn = $(this);
        $btn.prop("disabled", true).text("Connecting...");
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_linkedin_connect",
            nonce: $btn.data("nonce")
        }, function(r) {
            if (r.success && r.data.redirect) {
                window.location.href = r.data.redirect;
            } else {
                alert(r.data.message || "Error connecting to LinkedIn.");
                $btn.prop("disabled", false).text("Connect LinkedIn");
            }
        }).fail(function() {
            alert("Network error.");
            $btn.prop("disabled", false).text("Connect LinkedIn");
        });
    });

    $(document).on("click", ".zeko-linkedin-disconnect-btn", function() {
        if (!confirm("Disconnect LinkedIn account?")) return;
        var $btn = $(this);
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_linkedin_disconnect",
            nonce: $btn.data("nonce")
        }, function(r) {
            if (r.success) location.reload();
            else alert(r.data.message || "Error.");
        });
    });

    /* =============================================
       Resume Builder
       ============================================= */
    var zekoResumeData = null;

    // Tab switching
    $(document).on("click", ".zeko-resume-tab", function() {
        $(".zeko-resume-tab").removeClass("active").attr("aria-selected", "false");
        $(this).addClass("active").attr("aria-selected", "true");
        var tab = $(this).data("tab");
        $(".zeko-resume-tab-content").hide();
        $("#zeko-resume-tab-" + tab).show();
    });

    // New resume
    $(document).on("click", "#zeko-new-resume-btn", function() {
        zekoResumeData = { id: "", title: "", template: "modern", sections: { personal: { full_name: "", email: "", phone: "", location: "", website: "", linkedin: "", summary: "" }, experience: [], education: [], skills: [], certifications: [], projects: [] } };
        $("#zeko-resume-list, .zeko-empty-state").hide();
        $("#zeko-resume-editor").show();
        $("#zeko-resume-title").val("");
        $("#zeko-resume-template").val("modern");
        $("#zeko-rs-name").val($("#zeko-rs-name").val() || "");
        $("#zeko-rs-email").val($("#zeko-rs-email").val() || "");
        $("#zeko-rs-phone, #zeko-rs-location, #zeko-rs-website, #zeko-rs-linkedin, #zeko-rs-summary").val("");
        $("#zeko-rs-experience-list, #zeko-rs-education-list, #zeko-rs-certs-list, #zeko-rs-projects-list").empty();
        $("#zeko-rs-skills").val("");
        $(".zeko-resume-tab:first").click();
    });

    // Edit resume
    $(document).on("click", ".zeko-edit-resume-btn", function() {
        var resumeId = $(this).data("resume-id");
        // Find resume data from server-rendered list items
        var $item = $(".zeko-resume-list-item[data-resume-id='" + resumeId + "']");
        // We'll load it via a simple approach: fetch from PHP local var
        // Actually we need to reload page with ?edit_resume=ID or fetch via AJAX
        // For simplicity, let's reload with data attribute
        window.location.href = window.location.href.split("?")[0] + "?resume_edit=" + resumeId;
    });

    // Add experience entry
    $(document).on("click", "#zeko-rs-add-experience", function() {
        var idx = $("#zeko-rs-experience-list .zeko-rs-exp-entry").length;
        var html = '<div class="zeko-rs-exp-entry" style="padding:12px;border:1px solid var(--color-border);border-radius:8px;margin-bottom:12px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><strong>' + (idx + 1) + '</strong><button type="button" class="button-link zeko-rs-remove-entry" style="color:#dc3545;">Remove</button></div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            '<div class="zeko-field"><label>Job Title</label><input type="text" class="zeko-rs-exp-title"></div>' +
            '<div class="zeko-field"><label>Company</label><input type="text" class="zeko-rs-exp-company"></div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">' +
            '<div class="zeko-field"><label>Location</label><input type="text" class="zeko-rs-exp-location"></div>' +
            '<div class="zeko-field"><label>Start Date</label><input type="month" class="zeko-rs-exp-start"></div>' +
            '<div class="zeko-field"><label>End Date</label><input type="month" class="zeko-rs-exp-end"><label style="font-size:12px;margin-top:4px;"><input type="checkbox" class="zeko-rs-exp-current"> Current</label></div>' +
            '</div>' +
            '<div class="zeko-field"><label>Description</label><textarea class="zeko-rs-exp-desc" rows="3"></textarea></div>' +
            '</div>';
        $("#zeko-rs-experience-list").append(html);
    });

    // Add education entry
    $(document).on("click", "#zeko-rs-add-education", function() {
        var idx = $("#zeko-rs-education-list .zeko-rs-edu-entry").length;
        var html = '<div class="zeko-rs-edu-entry" style="padding:12px;border:1px solid var(--color-border);border-radius:8px;margin-bottom:12px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><strong>' + (idx + 1) + '</strong><button type="button" class="button-link zeko-rs-remove-entry" style="color:#dc3545;">Remove</button></div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            '<div class="zeko-field"><label>School</label><input type="text" class="zeko-rs-edu-school"></div>' +
            '<div class="zeko-field"><label>Degree</label><input type="text" class="zeko-rs-edu-degree"></div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">' +
            '<div class="zeko-field"><label>Field of Study</label><input type="text" class="zeko-rs-edu-field"></div>' +
            '<div class="zeko-field"><label>Start Date</label><input type="month" class="zeko-rs-edu-start"></div>' +
            '<div class="zeko-field"><label>End Date</label><input type="month" class="zeko-rs-edu-end"></div>' +
            '</div>' +
            '<div class="zeko-field"><label>Description</label><textarea class="zeko-rs-edu-desc" rows="2"></textarea></div>' +
            '</div>';
        $("#zeko-rs-education-list").append(html);
    });

    // Add certification entry
    $(document).on("click", "#zeko-rs-add-cert", function() {
        var html = '<div class="zeko-rs-cert-entry" style="padding:12px;border:1px solid var(--color-border);border-radius:8px;margin-bottom:12px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><strong>Certification</strong><button type="button" class="button-link zeko-rs-remove-entry" style="color:#dc3545;">Remove</button></div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            '<div class="zeko-field"><label>Name</label><input type="text" class="zeko-rs-cert-name"></div>' +
            '<div class="zeko-field"><label>Issuer</label><input type="text" class="zeko-rs-cert-issuer"></div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            '<div class="zeko-field"><label>Date</label><input type="month" class="zeko-rs-cert-date"></div>' +
            '<div class="zeko-field"><label>URL</label><input type="url" class="zeko-rs-cert-url" placeholder="https://"></div>' +
            '</div>' +
            '</div>';
        $("#zeko-rs-certs-list").append(html);
    });

    // Add project entry
    $(document).on("click", "#zeko-rs-add-project", function() {
        var html = '<div class="zeko-rs-proj-entry" style="padding:12px;border:1px solid var(--color-border);border-radius:8px;margin-bottom:12px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><strong>Project</strong><button type="button" class="button-link zeko-rs-remove-entry" style="color:#dc3545;">Remove</button></div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            '<div class="zeko-field"><label>Name</label><input type="text" class="zeko-rs-proj-name"></div>' +
            '<div class="zeko-field"><label>URL</label><input type="url" class="zeko-rs-proj-url" placeholder="https://"></div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            '<div class="zeko-field"><label>Start Date</label><input type="month" class="zeko-rs-proj-start"></div>' +
            '<div class="zeko-field"><label>End Date</label><input type="month" class="zeko-rs-proj-end"></div>' +
            '</div>' +
            '<div class="zeko-field"><label>Description</label><textarea class="zeko-rs-proj-desc" rows="2"></textarea></div>' +
            '</div>';
        $("#zeko-rs-projects-list").append(html);
    });

    // Remove entry
    $(document).on("click", ".zeko-rs-remove-entry", function() {
        $(this).closest(".zeko-rs-exp-entry, .zeko-rs-edu-entry, .zeko-rs-cert-entry, .zeko-rs-proj-entry").remove();
    });

    // Collect resume data from form
    function zekoCollectResume() {
        var experience = [];
        $("#zeko-rs-experience-list .zeko-rs-exp-entry").each(function() {
            experience.push({ title: $(this).find(".zeko-rs-exp-title").val(), company: $(this).find(".zeko-rs-exp-company").val(), location: $(this).find(".zeko-rs-exp-location").val(), start_date: $(this).find(".zeko-rs-exp-start").val(), end_date: $(this).find(".zeko-rs-exp-end").val(), description: $(this).find(".zeko-rs-exp-desc").val(), current: $(this).find(".zeko-rs-exp-current").is(":checked") });
        });
        var education = [];
        $("#zeko-rs-education-list .zeko-rs-edu-entry").each(function() {
            education.push({ school: $(this).find(".zeko-rs-edu-school").val(), degree: $(this).find(".zeko-rs-edu-degree").val(), field: $(this).find(".zeko-rs-edu-field").val(), start_date: $(this).find(".zeko-rs-edu-start").val(), end_date: $(this).find(".zeko-rs-edu-end").val(), description: $(this).find(".zeko-rs-edu-desc").val() });
        });
        var skills = $("#zeko-rs-skills").val().split(/[\n,]+/).map(function(s){ return s.trim(); }).filter(Boolean);
        var certs = [];
        $("#zeko-rs-certs-list .zeko-rs-cert-entry").each(function() {
            certs.push({ name: $(this).find(".zeko-rs-cert-name").val(), issuer: $(this).find(".zeko-rs-cert-issuer").val(), date: $(this).find(".zeko-rs-cert-date").val(), url: $(this).find(".zeko-rs-cert-url").val() });
        });
        var projects = [];
        $("#zeko-rs-projects-list .zeko-rs-proj-entry").each(function() {
            projects.push({ name: $(this).find(".zeko-rs-proj-name").val(), url: $(this).find(".zeko-rs-proj-url").val(), start_date: $(this).find(".zeko-rs-proj-start").val(), end_date: $(this).find(".zeko-rs-proj-end").val(), description: $(this).find(".zeko-rs-proj-desc").val() });
        });
        return {
            id: zekoResumeData ? zekoResumeData.id : "",
            title: $("#zeko-resume-title").val(),
            template: $("#zeko-resume-template").val(),
            sections: {
                personal: { full_name: $("#zeko-rs-name").val(), email: $("#zeko-rs-email").val(), phone: $("#zeko-rs-phone").val(), location: $("#zeko-rs-location").val(), website: $("#zeko-rs-website").val(), linkedin: $("#zeko-rs-linkedin").val(), summary: $("#zeko-rs-summary").val() },
                experience: experience, education: education, skills: skills, certifications: certs, projects: projects
            }
        };
    }

    // Save resume
    $(document).on("click", "#zeko-rs-save", function() {
        var data = zekoCollectResume();
        var $msg = $("#zeko-resume-message");
        $msg.text("Saving...").css("color", "");
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_save_resume",
            nonce: zeko_jobs_ajax.nonce,
            resume: JSON.stringify(data)
        }, function(r) {
            if (r.success) {
                zekoResumeData = data;
                zekoResumeData.id = r.data.resume_id;
                $msg.text(r.data.message).css("color", "#22c55e");
                zekoAnnounce(r.data.message);
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                $msg.text(r.data.message || "Error saving.").css("color", "#dc3545");
            }
        }).fail(function() { $msg.text("Network error.").css("color", "#dc3545"); });
    });

    // Cancel
    $(document).on("click", "#zeko-rs-cancel-btn", function() {
        $("#zeko-resume-editor, #zeko-resume-preview").hide();
        $("#zeko-resume-list, .zeko-empty-state").show();
    });

    // Preview resume
    $(document).on("click", "#zeko-rs-preview-btn, .zeko-preview-resume-btn", function() {
        var data = zekoCollectResume();
        if (!data) return;
        var p = data.sections.personal;
        var html = '<div class="zeko-resume-render zeko-resume-' + data.template + '">';
        html += '<div style="text-align:center;margin-bottom:24px;"><h1 style="margin:0;font-size:24px;">' + (p.full_name || "Your Name") + '</h1>';
        var contactParts = [];
        if (p.email) contactParts.push(p.email);
        if (p.phone) contactParts.push(p.phone);
        if (p.location) contactParts.push(p.location);
        if (p.website) contactParts.push(p.website);
        if (p.linkedin) contactParts.push(p.linkedin);
        if (contactParts.length) html += '<p style="margin:4px 0 0;color:#666;font-size:14px;">' + contactParts.join(" | ") + '</p>';
        html += '</div>';
        if (p.summary) html += '<div style="margin-bottom:16px;"><h2 style="font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;">Summary</h2><p style="font-size:13px;line-height:1.6;">' + p.summary.replace(/\n/g, "<br>") + '</p></div>';
        if (data.sections.experience.length) {
            html += '<div style="margin-bottom:16px;"><h2 style="font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;">Experience</h2>';
            data.sections.experience.forEach(function(e) {
                html += '<div style="margin-bottom:12px;"><div style="display:flex;justify-content:space-between;"><strong>' + (e.title || "") + '</strong><span style="color:#666;font-size:13px;">' + (e.start_date || "") + ' – ' + (e.current ? "Present" : (e.end_date || "")) + '</span></div>';
                html += '<div style="color:#666;font-size:13px;">' + (e.company || "") + (e.location ? ", " + e.location : "") + '</div>';
                if (e.description) html += '<p style="font-size:13px;margin:4px 0 0;">' + e.description.replace(/\n/g, "<br>") + '</p>';
                html += '</div>';
            });
            html += '</div>';
        }
        if (data.sections.education.length) {
            html += '<div style="margin-bottom:16px;"><h2 style="font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;">Education</h2>';
            data.sections.education.forEach(function(e) {
                html += '<div style="margin-bottom:8px;"><div style="display:flex;justify-content:space-between;"><strong>' + (e.degree || "") + (e.field ? " in " + e.field : "") + '</strong><span style="color:#666;font-size:13px;">' + (e.start_date || "") + ' – ' + (e.end_date || "") + '</span></div>';
                html += '<div style="color:#666;font-size:13px;">' + (e.school || "") + '</div>';
                if (e.description) html += '<p style="font-size:13px;margin:4px 0 0;">' + e.description.replace(/\n/g, "<br>") + '</p>';
                html += '</div>';
            });
            html += '</div>';
        }
        if (data.sections.skills.length) {
            html += '<div style="margin-bottom:16px;"><h2 style="font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;">Skills</h2>';
            html += '<p style="font-size:13px;">' + data.sections.skills.join(" · ") + '</p></div>';
        }
        if (data.sections.certifications.length) {
            html += '<div style="margin-bottom:16px;"><h2 style="font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;">Certifications</h2>';
            data.sections.certifications.forEach(function(c) {
                html += '<div style="margin-bottom:4px;font-size:13px;"><strong>' + (c.name || "") + '</strong> – ' + (c.issuer || "") + (c.date ? " (" + c.date + ")" : "") + '</div>';
            });
            html += '</div>';
        }
        if (data.sections.projects.length) {
            html += '<div style="margin-bottom:16px;"><h2 style="font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;">Projects</h2>';
            data.sections.projects.forEach(function(pj) {
                html += '<div style="margin-bottom:8px;font-size:13px;"><strong>' + (pj.name || "") + '</strong>';
                if (pj.url) html += ' – <a href="' + pj.url + '">' + pj.url + '</a>';
                html += '<span style="color:#666;"> (' + (pj.start_date || "") + ' – ' + (pj.end_date || "") + ')</span>';
                if (pj.description) html += '<p style="margin:4px 0 0;">' + pj.description.replace(/\n/g, "<br>") + '</p>';
                html += '</div>';
            });
            html += '</div>';
        }
        html += '</div>';
        $("#zeko-resume-editor").hide();
        $("#zeko-resume-preview").show();
        $("#zeko-resume-render").html(html);
    });

    // Back to editor
    $(document).on("click", "#zeko-rs-back-to-editor", function() {
        $("#zeko-resume-preview").hide();
        $("#zeko-resume-editor").show();
    });

    // Print/Export PDF
    $(document).on("click", "#zeko-rs-print", function() {
        var content = $("#zeko-resume-render").html();
        var win = window.open("", "_blank");
        win.document.write('<html><head><title>Resume</title><style>@media print{body{margin:0;}.no-print{display:none!important;}}body{font-family:Georgia,"Times New Roman",serif;color:#333;line-height:1.5;padding:20px;}h1{margin:0 0 4px;}h2{font-size:16px;border-bottom:2px solid #333;padding-bottom:4px;margin:16px 0 8px;}p{margin:4px 0;}</style></head><body>' + content + '<script>window.onload=function(){window.print();}<\/script></body></html>');
        win.document.close();
    });

    // Delete resume
    $(document).on("click", ".zeko-delete-resume-btn", function() {
        if (!confirm("Delete this resume?")) return;
        var $btn = $(this);
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_delete_resume",
            nonce: $btn.data("nonce"),
            resume_id: $btn.data("resume-id")
        }, function(r) {
            if (r.success) { location.reload(); }
            else { alert(r.data.message || "Error."); }
        });
    });

    // Set default resume
    $(document).on("click", ".zeko-set-default-resume-btn", function() {
        var $btn = $(this);
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_set_default_resume",
            nonce: $btn.data("nonce"),
            resume_id: $btn.data("resume-id")
        }, function(r) {
            if (r.success) { location.reload(); }
            else { alert(r.data.message || "Error."); }
        });
    });

    // Export from list
    $(document).on("click", ".zeko-export-resume-btn", function() {
        alert("Please edit the resume first, then use the Preview → Export PDF button.");
    });

    // ── ZekoPay Billing ──

    // Load billing balance and history when billing section is shown.
    $(document).on("click", 'a[href="#zeko-dashboard-billing"]', function() {
        var $balance = $(".zeko-billing-balance");
        var $freeLeft = $(".zeko-billing-free-left");
        var nonce = $balance.data("nonce");
        if (!nonce) return;

        // Fetch balance.
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_check_balance",
            nonce: nonce
        }, function(r) {
            if (r.success) {
                $balance.text("$" + parseFloat(r.data.balance).toFixed(2));
                if (r.data.payment_type === "free") {
                    $freeLeft.text("Yes (using free posting)");
                } else if (r.data.payment_type === "pack") {
                    $freeLeft.text("Yes (using listing pack)");
                } else {
                    $freeLeft.text("No");
                }
            }
        });

        // Fetch billing history.
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_billing_history",
            nonce: nonce
        }, function(r) {
            if (r.success && r.data.history && r.data.history.length) {
                var html = '<table class="widefat"><thead><tr><th>' + "Date" + '</th><th>' + "Type" + '</th><th>' + "Description" + '</th><th>' + "Amount" + '</th><th>' + "Status" + '</th></tr></thead><tbody>';
                r.data.history.forEach(function(row) {
                    var typeLabels = { posting_fee: "Job Posting", boost_fee: "Job Boost", listing_pack: "Listing Pack" };
                    var label = typeLabels[row.type] || row.type;
                    var statusClass = row.status === "completed" ? "color:#065f46" : (row.status === "refunded" ? "color:#b45309" : "color:#991b1b");
                    html += '<tr><td>' + row.created_at + '</td><td>' + label + '</td><td>' + (row.job_title || row.description || "") + '</td><td style="font-weight:600;">$' + parseFloat(row.amount).toFixed(2) + '</td><td style="' + statusClass + ';font-weight:600;">' + row.status + '</td></tr>';
                });
                html += '</tbody></table>';
                $("#zeko-billing-history").html(html);
            } else {
                $("#zeko-billing-history").html('<p style="color:var(--color-text-muted);">' + "No transactions yet." + '</p>');
            }
        });
    });

    // Buy listing pack.
    $(document).on("click", ".zeko-buy-listing-pack-btn", function() {
        var $btn = $(this);
        var $msg = $("#zeko-pack-message");
        if (!confirm("Purchase listing pack? The fee will be deducted from your ZekoPay wallet.")) return;
        $btn.prop("disabled", true);
        $msg.text("Processing...");
        $.post(zeko_jobs_ajax.ajax_url, {
            action: "zeko_job_buy_listing_pack",
            nonce: $btn.data("nonce")
        }, function(r) {
            $btn.prop("disabled", false);
            if (r.success) {
                $msg.css("color", "#065f46").text(r.data.message);
            } else {
                $msg.css("color", "#991b1b").text(r.data.message || "Error.");
                if (r.data.redirect) {
                    setTimeout(function() { window.open(r.data.redirect, "_blank"); }, 1500);
                }
            }
        });
    });

    // Intercept job create to handle payment.
    // Override the form submit to check payment first.
    $(document).on("submit", "#zeko-job-create-form", function(e) {
        // The payment charge is handled server-side in handle_job_create.
        // But we can show a "Processing payment..." message.
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        $submitBtn.prop("disabled", true).text("Processing...");
    });

    // Handle boost payment from featured toggle.
    var originalToggleFeatured = null;
    $(document).on("click", ".zeko-featured-toggle-btn", function(e) {
        var $btn = $(this);
        var isCurrentlyFeatured = $btn.hasClass("is-featured") || $btn.hasClass("zeko-is-featured");
        // If not currently featured, this is a boost (requires payment).
        if (!isCurrentlyFeatured) {
            if (!confirm("Boost this job? A fee will be deducted from your ZekoPay wallet.")) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        }
    });

});

