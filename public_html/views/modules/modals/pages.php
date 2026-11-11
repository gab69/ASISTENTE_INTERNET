<!--=============================================
Agregar / editar página del menú
==============================================-->

<div class="modal fade" id="myPage" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">

      <form method="POST" class="needs-validation" novalidate autocomplete="off">

        <div class="modal-header">
          <h5 class="modal-title" id="pageModalTitle">Nueva página</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body px-4">

          <div class="row g-3">

            <!-- Vista previa en el menú -->

            <div class="col-12">
              <div class="page-preview">
                <span class="page-preview-icon"><i id="pagePreviewIcon" class="bi bi-file-earmark-text"></i></span>
                <div>
                  <div class="page-preview-title" id="pagePreviewTitle">Nombre de la página</div>
                  <div class="page-preview-url" id="pagePreviewUrl">/</div>
                </div>
                <span class="ms-auto small text-muted">Así se verá en el menú</span>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="title_page">Nombre en el menú <sup>*</sup></label>
              <input type="text" class="form-control" id="title_page" name="title_page" maxlength="40" placeholder="Ej.: Proveedores" required>
              <div class="invalid-feedback">Escribe el nombre de la página.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="url_page">Dirección (URL) <sup>*</sup></label>
              <div class="input-group">
                <span class="input-group-text">/</span>
                <input type="text" class="form-control" id="url_page" name="url_page" placeholder="proveedores" required>
              </div>
              <div class="form-text" id="urlHelp">Se completa sola a partir del nombre.</div>
            </div>

            <!-- Tipo de página -->

            <div class="col-12">
              <label class="form-label">¿Qué tipo de página es? <sup>*</sup></label>
              <input type="hidden" id="type_page" name="type_page" value="modules">
              <div class="type-cards">
                <button type="button" class="type-card active" data-type="modules">
                  <i class="bi bi-table"></i>
                  <strong>Tablas y gráficos</strong>
                  <span>Para listas de datos, métricas y gráficos.</span>
                </button>
                <button type="button" class="type-card" data-type="custom">
                  <i class="bi bi-code-slash"></i>
                  <strong>Personalizada</strong>
                  <span>Página en blanco para programar a medida.</span>
                </button>
                <button type="button" class="type-card" data-type="internal_link">
                  <i class="bi bi-link-45deg"></i>
                  <strong>Enlace interno</strong>
                  <span>Acceso directo a otra parte del panel.</span>
                </button>
                <button type="button" class="type-card" data-type="external_link">
                  <i class="bi bi-box-arrow-up-right"></i>
                  <strong>Enlace externo</strong>
                  <span>Abre otro sitio web en una pestaña nueva.</span>
                </button>
              </div>
            </div>

            <!-- Icono -->

            <div class="col-12">
              <label class="form-label" for="icon_page">Ícono <sup>*</sup></label>

              <div class="icon-picker">

                <div class="icon-picker-bar">
                  <div class="icon-search">
                    <i class="bi bi-search"></i>
                    <input type="search" id="iconSearch" placeholder="Buscar ícono: venta, cliente, calendario…">
                  </div>
                  <select class="form-select form-select-sm" id="iconGroup" aria-label="Categoría de íconos"></select>
                </div>

                <div class="icon-grid" id="iconGrid" role="listbox" aria-label="Íconos"></div>

                <details class="icon-custom">
                  <summary>Usar otro ícono (código)</summary>
                  <input type="text" class="form-control form-control-sm mt-2 cleanIcon" id="icon_page" name="icon_page" value="bi bi-file-earmark-text" required>
                  <div class="form-text">Puedes pegar cualquier clase de <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">Bootstrap Icons</a> (ej.: <code>bi bi-star</code>) o de <a href="https://fontawesome.com/v5/search?m=free" target="_blank" rel="noopener">Font Awesome 5</a>.</div>
                </details>

              </div>
            </div>

          </div>

        </div>

        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-brand"><i class="bi bi-check2"></i> Guardar página</button>
        </div>

      </form>

    </div>
  </div>
</div>
