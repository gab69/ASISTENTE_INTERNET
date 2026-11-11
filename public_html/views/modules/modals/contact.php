<!-- The Modal -->
<div class="modal fade" id="myContact">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded">

      <form method="POST" class="needs-validation" novalidate>

        <input type="hidden" id="id_contact" name="id_contact">

        <!-- Modal Header -->
        <div class="modal-header">
          <h4 class="modal-title">Editar Contacto</h4>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <!-- Modal body -->
        <div class="modal-body px-4">

          <!--========================================
          Telefóno
          ============================================-->
          
          <div class="form-group mb-3">
            
            <label for="phone_contact">Teléfono</label>

            <input 
            type="text"
            class="form-control rounded form-control-sm"
            id="phone_contact"
            name="phone_contact"
            readonly
            required>

          </div>

          <!--========================================
          Nombre
          ============================================-->

          <div class="form-group mb-3">
            
            <label for="name_contact">Nombre</label>

            <input 
            type="text"
            class="form-control rounded form-control-sm"
            id="name_contact"
            name="name_contact"
            required>

          </div>

        </div>

        <!-- Modal footer -->
        <div class="modal-footer d-flex justify-content-between">

          <div>
            <button type="button" class="btn btn-dark rounded" data-bs-dismiss="modal">Cerrar</button>
          </div>

          <div>
            <button type="submit" class="btn btn-dafault backColor rounded">Guardar</button>
          </div>
        </div>

      </form>

    </div>
  </div>
</div>