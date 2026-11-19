<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-sync"></i> {{Démon}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Relevé de l'état toutes les}}</label>
			<div class="col-md-2">
				<div class="input-group">
					<input type="number" class="configKey form-control" data-l1key="poll_interval" placeholder="10" min="3" max="300">
					<span class="input-group-addon">s</span>
				</div>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Le démon demande son état à chaque lampe à ce rythme, et juste après chaque ordre. Un changement fait depuis l'application Govee ou à la voix apparaît donc au plus tard après ce délai. Redémarrez le démon après modification.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Port de contrôle local}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="daemon_port" placeholder="55241" min="1025" max="65535">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Port TCP, sur 127.0.0.1 uniquement, par lequel le plugin passe ses ordres au démon. À changer seulement s'il est déjà pris.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Ports réseau des lampes}}</label>
			<div class="col-md-7">
				<span class="help-block" style="margin:0;">{{Fixés par Govee : UDP 4001 (recherche) et 4003 (ordres) vers la lampe, UDP 4002 pour ses réponses vers Jeedom. Si les lampes sont sur un autre réseau ou VLAN, le pare-feu doit laisser passer ces trois ports dans les deux sens. Aucun autre logiciel Govee (govee2mqtt…) ne doit occuper le port 4002 sur cette machine.}}</span>
			</div>
		</div>
	</fieldset>
</form>
