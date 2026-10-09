<?php // Included only in an authenticated owner/admin trips page. ?>
<dialog id="tripDeleteDialog" class="tf-merge-dialog tf-delete-dialog" aria-labelledby="tripDeleteTitle">
  <h3 id="tripDeleteTitle">Fahrt endg&uuml;ltig l&ouml;schen?</h3>
  <p><strong id="tripDeleteTarget">Fahrt</strong> wird aus der Fahrtenhistorie, aus Statistiken und aus verbundenen Touren entfernt. Bestehende Rohtelemetrie bleibt f&uuml;r Diagnosen erhalten.</p>
  <p>Diese Aktion kann nur durch ein Backup r&uuml;ckg&auml;ngig gemacht werden. Laufende Fahrten k&ouml;nnen nicht gel&ouml;scht werden.</p>
  <form id="tripDeleteForm" action="trip-delete.php" method="post">
    <?= Csrf::field() ?>
    <input id="tripDeleteId" name="trip_id" type="hidden" value="">
    <input type="hidden" name="confirm" value="delete">
    <label class="trip-delete-confirm-label"><input type="checkbox" id="tripDeleteAcknowledge" required> Ich m&ouml;chte diese Fahrt unwiderruflich l&ouml;schen.</label>
  </form>
  <div class="split-actions">
    <button class="btn btn-ghost" id="tripDeleteCancel" type="button">Abbrechen</button>
    <button class="btn trip-delete-danger" id="tripDeleteConfirm" form="tripDeleteForm" type="submit" disabled>Fahrt l&ouml;schen</button>
  </div>
</dialog>
