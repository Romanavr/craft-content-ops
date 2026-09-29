/* Content Ops — bulk edit modal */
(function($) {
  Craft.ContentOps = Craft.ContentOps || {};

  const t = (message, params) => Craft.t('content-ops', message, params);

  Craft.ContentOps.BulkEdit = Garnish.Modal.extend(
    {
      elementIndex: null,
      selectedIds: null,
      sites: null,
      targets: null,
      total: 0,
      changesetId: null,

      $form: null,
      $body: null,
      $editor: null,
      $rows: null,
      $preview: null,
      $previewBtn: null,
      $applyBtn: null,
      $backBtn: null,
      $spinner: null,

      init: function(elementIndex, selectedIds, sites) {
        this.elementIndex = elementIndex;
        this.selectedIds = selectedIds;
        this.sites = sites;

        this.$form = $('<form class="modal fitted co-bulk-edit" method="post" accept-charset="UTF-8"/>').appendTo(Garnish.$bod);
        this.$body = $('<div class="body"/>').appendTo(this.$form);
        $('<h1/>', {text: t('Bulk edit')}).appendTo(this.$body);

        this.$editor = $('<div class="co-editor"/>').appendTo(this.$body);
        this.buildScope();
        this.$rows = $('<div class="co-rows"/>').appendTo(this.$editor);
        const $addBtn = $('<button type="button" class="btn dashed add icon"/>')
          .text(t('Add another change'))
          .appendTo(this.$editor);
        this.addListener($addBtn, 'activate', () => this.addRow());

        this.$preview = $('<div class="co-preview-container hidden"/>').appendTo(this.$body);

        const $footer = $('<div class="footer"/>').appendTo(this.$form);
        const $buttons = $('<div class="buttons right"/>').appendTo($footer);
        this.$spinner = $('<div class="spinner hidden"/>').appendTo($buttons);
        const $cancelBtn = $('<button type="button" class="btn"/>').text(Craft.t('app', 'Cancel')).appendTo($buttons);
        this.$backBtn = $('<button type="button" class="btn hidden"/>').text(t('Back')).appendTo($buttons);
        this.$previewBtn = $('<button type="submit" class="btn submit"/>').text(t('Preview changes')).appendTo($buttons);
        this.$applyBtn = $('<button type="button" class="btn submit hidden"/>').appendTo($buttons);

        this.addListener($cancelBtn, 'activate', 'hide');
        this.addListener(this.$backBtn, 'activate', 'showEditor');
        this.addListener(this.$applyBtn, 'activate', 'apply');
        this.addListener(this.$form, 'submit', (ev) => {
          ev.preventDefault();
          this.preview();
        });

        this.base(this.$form, {hideOnEsc: true, onHide: () => this.destroy()});
        this.loadTargets();
      },

      buildScope: function() {
        const $scope = $('<div class="co-scope"/>').appendTo(this.$editor);
        const total = this.elementIndex.totalResults;
        const selected = this.selectedIds.length;
        const radio = (value, label, checked) =>
          $('<label class="co-radio"/>')
            .append($('<input type="radio" name="scope"/>').val(value).prop('checked', checked))
            .append(document.createTextNode(' ' + label));

        radio('selected', t('{num, plural, =1{The selected entry} other{The # selected entries}}', {num: selected}), true).appendTo($scope);

        if (total && total > selected) {
          radio('all', t('All {num, number} entries matching the current view', {num: total}), false).appendTo($scope);
        }

        this.addListener($scope.find('input'), 'change', 'loadTargets');

        if (this.sites.length > 1) {
          const currentSiteId = this.elementIndex.siteId;
          const $sites = $('<div class="co-sites"/>').append($('<span class="light"/>').text(t('Sites:') + ' '));
          this.sites.forEach((site) => {
            $('<label class="co-checkbox"/>')
              .append($('<input type="checkbox" name="siteIds[]"/>').val(site.id).prop('checked', site.id == currentSiteId))
              .append(document.createTextNode(' ' + site.name))
              .appendTo($sites);
          });
          $sites.appendTo($scope);
        }
      },

      scope: function() {
        return this.$editor.find('input[name="scope"]:checked').val() || 'selected';
      },

      siteIds: function() {
        const ids = this.$editor.find('input[name="siteIds[]"]:checked').map((i, el) => parseInt(el.value)).get();
        return ids.length ? ids : [this.elementIndex.siteId];
      },

      requestData: function(extra) {
        return Object.assign(this.elementIndex.getViewParams(), {
          scope: this.scope(),
          elementIds: this.selectedIds,
        }, extra || {});
      },

      loadTargets: async function() {
        this.setBusy(true);
        try {
          const {data} = await Craft.sendActionRequest('POST', 'content-ops/bulk-edit/targets', {data: this.requestData()});
          this.targets = data.targets;
          this.total = data.total;
          this.$rows.empty();
          this.addRow();
        } catch (e) {
          Craft.cp.displayError(e?.response?.data?.message || t('Couldn’t load fields.'));
        } finally {
          this.setBusy(false);
        }
      },

      addRow: function() {
        new Craft.ContentOps.BulkEditRow(this, this.$rows);
      },

      operations: function() {
        return this.$rows.children('.co-row').map((i, el) => $(el).data('row').serialize()).get().filter((op) => op !== null);
      },

      preview: async function() {
        const operations = this.operations();
        if (!operations.length) {
          Craft.cp.displayError(t('Choose a field to change.'));
          return;
        }

        this.setBusy(true);
        try {
          const {data} = await Craft.sendActionRequest('POST', 'content-ops/bulk-edit/preview', {
            data: this.requestData({siteIds: this.siteIds(), operations}),
          });
          this.changesetId = data.changesetId;
          this.$preview.html(data.html);
          Craft.initUiElements(this.$preview);
          const pending = data.counts.pending || 0;
          this.$applyBtn
            .text(t('{num, plural, =1{Apply # change} other{Apply # changes}}', {num: pending}))
            .toggleClass('disabled', pending === 0)
            .prop('disabled', pending === 0);
          this.showPreview();
        } catch (e) {
          Craft.cp.displayError(e?.response?.data?.message || t('Couldn’t preview the changes.'));
        } finally {
          this.setBusy(false);
        }
      },

      showPreview: function() {
        this.$editor.addClass('hidden');
        this.$preview.removeClass('hidden');
        this.$previewBtn.addClass('hidden');
        this.$backBtn.removeClass('hidden');
        this.$applyBtn.removeClass('hidden');
        this.updateSizeAndPosition();
      },

      showEditor: function() {
        this.$preview.addClass('hidden').empty();
        this.$editor.removeClass('hidden');
        this.$previewBtn.removeClass('hidden');
        this.$backBtn.addClass('hidden');
        this.$applyBtn.addClass('hidden');
        this.changesetId = null;
        this.updateSizeAndPosition();
      },

      apply: async function() {
        if (!this.changesetId || this.$applyBtn.prop('disabled')) {
          return;
        }

        this.setBusy(true);
        try {
          await Craft.sendActionRequest('POST', 'content-ops/changesets/apply', {data: {changesetId: this.changesetId}});
          Craft.cp.displayNotice(t('Applying changes in the background…'));
          Craft.cp.runQueue();
          Craft.ContentOps.BulkEdit.watch(this.changesetId, this.elementIndex);
          this.hide();
        } catch (e) {
          Craft.cp.displayError(e?.response?.data?.message || t('Couldn’t apply the changes.'));
        } finally {
          this.setBusy(false);
        }
      },

      setBusy: function(busy) {
        this.$spinner.toggleClass('hidden', !busy);
        this.$previewBtn.toggleClass('disabled', busy);
      },

      destroy: function() {
        this.$form.remove();
        this.$shade?.remove();
        this.base();
      },
    },
    {
      register: function(type, sites) {
        new Craft.ElementActionTrigger({
          type: type,
          bulk: true,
          activate: (selectedItems, elementIndex) => {
            new Craft.ContentOps.BulkEdit(elementIndex, elementIndex.view.getSelectedElementIds(), sites);
          },
        });
      },

      /**
       * Polls a changeset until it's done, then refreshes the index.
       */
      watch: function(changesetId, elementIndex) {
        const startedAt = Date.now();
        const poll = async () => {
          // Give up after 30 minutes; the changeset keeps running and shows up in the history.
          if (Date.now() - startedAt > 30 * 60 * 1000) {
            return;
          }
          try {
            const {data} = await Craft.sendActionRequest('GET', 'content-ops/changesets/status', {params: {id: changesetId}});
            if (!['queued', 'running'].includes(data.status)) {
              const c = data.counts;
              const message = t('Changeset #{id}: {applied, number} applied, {conflict, number} conflicts, {failed, number} failed.', {
                id: changesetId,
                applied: c.applied || 0,
                conflict: c.conflict || 0,
                failed: c.failed || 0,
              });
              data.status === 'applied' && !c.failed ? Craft.cp.displaySuccess(message) : Craft.cp.displayError(message);
              elementIndex.updateElements();
              return;
            }
          } catch (e) {
            // keep polling; the queue may just be busy
          }
          // Back off gradually so long jobs don't flood the server with requests.
          setTimeout(poll, Math.min(10000, 1500 + (Date.now() - startedAt) / 20));
        };
        setTimeout(poll, 1000);
      },
    }
  );

  /**
   * One “change” row: target → operation → inputs.
   */
  Craft.ContentOps.BulkEditRow = Garnish.Base.extend({
    modal: null,
    target: null,
    $row: null,
    $target: null,
    $operation: null,
    $inputs: null,

    init: function(modal, $container) {
      this.modal = modal;
      this.$row = $('<div class="co-row"/>').data('row', this).appendTo($container);

      const $targetWrap = $('<div class="select co-target"/>').appendTo(this.$row);
      this.$target = $('<select/>').appendTo($targetWrap);
      $('<option value=""/>').text(t('Choose a field…')).appendTo(this.$target);

      const groups = {};
      modal.targets.forEach((target) => {
        if (!groups[target.group]) {
          groups[target.group] = $('<optgroup/>').attr('label', t(target.group)).appendTo(this.$target);
        }
        const label = target.count < target.total
          ? t('{label} (applies to {count, number} of {total, number})', target)
          : target.label;
        $('<option/>').val(target.handle).text(label).appendTo(groups[target.group]);
      });

      const $operationWrap = $('<div class="select co-operation hidden"/>').appendTo(this.$row);
      this.$operation = $('<select/>').appendTo($operationWrap);
      this.$inputs = $('<div class="co-inputs"/>').appendTo(this.$row);

      const $remove = $('<button type="button" class="delete icon co-remove"/>').attr('title', Craft.t('app', 'Remove')).appendTo(this.$row);
      this.addListener($remove, 'activate', () => {
        this.$row.remove();
        this.destroy();
      });

      this.addListener(this.$target, 'change', 'onTargetChange');
      this.addListener(this.$operation, 'change', 'renderInputs');
    },

    onTargetChange: function() {
      this.target = this.modal.targets.find((target) => target.handle === this.$target.val()) || null;
      this.$operation.empty();
      this.$inputs.empty();
      this.$operation.parent().toggleClass('hidden', !this.target);

      if (!this.target) {
        return;
      }

      this.target.operations.forEach((operation) => {
        $('<option/>').val(operation.handle).text(t(operation.label)).appendTo(this.$operation);
      });
      this.renderInputs();
    },

    operation: function() {
      return this.target?.operations.find((operation) => operation.handle === this.$operation.val()) || null;
    },

    renderInputs: function() {
      this.$inputs.empty();
      const operation = this.operation();
      (operation?.inputs || []).forEach((input) => this.renderInput(input));
      this.modal.updateSizeAndPosition();
    },

    renderInput: function(input) {
      const $field = $('<div class="co-input"/>').attr('data-name', input.name).attr('data-type', input.type).appendTo(this.$inputs);
      const label = t(input.label);

      switch (input.type) {
        case 'textarea':
          $('<textarea class="text fullwidth" rows="4"/>').attr('aria-label', label).attr('placeholder', label).appendTo($field);
          break;
        case 'number':
          $('<input type="number" step="any" class="text"/>').attr('aria-label', label).attr('placeholder', label).appendTo($field);
          break;
        case 'datetime':
          $('<input type="datetime-local" class="text"/>').attr('aria-label', label).appendTo($field);
          break;
        case 'select': {
          const $select = $('<select/>').attr('aria-label', label);
          input.options.forEach((option) => $('<option/>').val(option.value).text(option.label).appendTo($select));
          $('<div class="select"/>').append($select).appendTo($field);
          break;
        }
        case 'checkboxes':
          input.options.forEach((option) => {
            $('<label class="co-checkbox"/>')
              .append($('<input type="checkbox"/>').val(option.value))
              .append(document.createTextNode(' ' + option.label))
              .appendTo($field);
          });
          break;
        case 'lightswitch':
          $('<label class="co-checkbox"/>')
            .append($('<input type="checkbox"/>').prop('checked', input.default !== false))
            .append(document.createTextNode(' ' + label))
            .appendTo($field);
          break;
        case 'elements': {
          const $chips = $('<div class="co-chips"/>').appendTo($field);
          const $btn = $('<button type="button" class="btn add icon dashed"/>').text(t('Choose {label}', {label: label.toLowerCase()})).appendTo($field);
          $field.data('ids', []);
          this.addListener($btn, 'activate', () => {
            Craft.createElementSelectorModal(input.elementType, {
              sources: input.sources,
              multiSelect: true,
              disabledElementIds: $field.data('ids'),
              onSelect: (elements) => {
                elements.forEach((element) => {
                  $field.data('ids').push(element.id);
                  const $chip = $('<span class="co-chip"/>').text(element.label + ' ');
                  const $x = $('<button type="button" class="co-chip-remove" aria-label="Remove">×</button>').appendTo($chip);
                  $x.on('click', () => {
                    $field.data('ids', $field.data('ids').filter((id) => id !== element.id));
                    $chip.remove();
                  });
                  $chip.appendTo($chips);
                });
              },
            });
          });
          break;
        }
        default:
          $('<input type="text" class="text fullwidth"/>').attr('aria-label', label).attr('placeholder', input.placeholder || label).appendTo($field);
      }
    },

    serialize: function() {
      const operation = this.operation();
      if (!this.target || !operation) {
        return null;
      }

      const options = {};
      this.$inputs.children('.co-input').each((i, el) => {
        const $field = $(el);
        const name = $field.data('name');
        switch ($field.data('type')) {
          case 'checkboxes':
            options[name] = $field.find('input:checked').map((j, cb) => cb.value).get();
            break;
          case 'lightswitch':
            options[name] = $field.find('input').prop('checked');
            break;
          case 'elements':
            options[name] = $field.data('ids');
            break;
          default:
            options[name] = $field.find('input, textarea, select').val();
        }
      });

      return {
        target: this.target.handle,
        operator: this.target.operator,
        operation: operation.handle,
        options: options,
      };
    },
  });
})(jQuery);
