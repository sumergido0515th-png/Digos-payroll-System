/* Minimal stand-in for bootstrap.bundle.min.js, just enough for app.js's
   usage (Toast, Modal.getOrCreateInstance/getInstance/hide/show), since this
   sandbox cannot reach the real CDN. Not a design test - functional only. */
(function () {
  function ToastStub(el) { this.el = el; }
  ToastStub.prototype.show = function () { this.el.classList.add('show'); };
  ToastStub.prototype.hide = function () {
    this.el.classList.remove('show');
    this.el.dispatchEvent(new Event('hidden.bs.toast'));
  };

  function ModalStub(el) { this.el = el; }
  ModalStub.prototype.show = function () { this.el.classList.add('show'); this.el.style.display = 'block'; };
  ModalStub.prototype.hide = function () {
    var evt = new Event('hide.bs.modal', { cancelable: true });
    this.el.dispatchEvent(evt);
    if (evt.defaultPrevented) return;
    this.el.classList.remove('show');
    this.el.style.display = 'none';
  };
  var instances = new WeakMap();
  ModalStub.getOrCreateInstance = function (el) {
    if (!instances.has(el)) instances.set(el, new ModalStub(el));
    return instances.get(el);
  };
  ModalStub.getInstance = function (el) { return instances.get(el) || null; };

  window.bootstrap = { Toast: ToastStub, Modal: ModalStub };
})();
