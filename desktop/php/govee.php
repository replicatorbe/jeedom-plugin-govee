<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('govee');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor logoPrimary" id="bt_goveeScan">
				<i class="fas fa-search"></i>
				<br>
				<span>{{Rechercher des lampes}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<!-- Recherche : panneau caché jusqu'au clic. -->
		<div id="div_goveeSearch" style="display:none;margin:5px;">
			<legend><i class="fas fa-search"></i> {{Recherche}}</legend>
			<div class="form-inline" style="margin-bottom:10px;">
				<span>{{Adresses IP à interroger en plus (lampes sur un autre réseau ou VLAN) :}}</span>
				<input type="text" class="form-control input-sm" id="in_goveeIps" placeholder="192.168.30.76" style="width:260px;">
				<a class="btn btn-primary btn-sm" id="bt_goveeScanGo"><i class="fas fa-search"></i> {{Chercher}}</a>
				<a class="btn btn-default btn-sm" id="bt_goveeSearchClose"><i class="fas fa-times"></i> {{Fermer}}</a>
			</div>
			<div id="div_goveeSearchStatus" class="alert alert-info" style="display:none;"></div>
			<div id="div_goveeSearchResults"></div>
		</div>

		<legend><i class="fas fa-lightbulb"></i> {{Mes lampes Govee}}</legend>
		<?php
		if (count($eqLogics) === 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune lampe pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Dans l\'application Govee, ouvrez les réglages de la lampe et activez « LAN Control » (Contrôle LAN).}}</li>';
			echo '<li>{{Cliquez sur « Rechercher des lampes ». Si la lampe est sur un autre réseau ou VLAN que Jeedom, saisissez son adresse IP : la recherche automatique ne traverse pas les VLAN.}}</li>';
			echo '<li>{{Créez les lampes trouvées : les commandes et l\'état arrivent aussitôt.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<img src="' . $plugin->getPathImgIcon() . '">';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-info">' . htmlspecialchars((string) $eqLogic->getConfiguration('sku', '?')) . '</span> ';
			echo '<span class="label label-default">' . htmlspecialchars((string) $eqLogic->getConfiguration('ip', '')) . '</span> ';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Lampadaire du salon}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-plug"></i> {{Lampe}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.30.76">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Donnez-lui une adresse fixe dans votre routeur : la recherche horaire ne la retrouve que sur le réseau de Jeedom.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Profil}}</label>
								<div class="col-sm-5">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="profile">
										<option value="auto">{{Automatique (selon le modèle)}}</option>
										<option value="standard">{{Lampe simple}}</option>
										<option value="uplighter">{{Uplighter, trois zones (H60B0)}}</option>
									</select>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Le profil Uplighter ajoute les commandes de chaque zone. Forcez-le pour essayer un modèle voisin (H60B3…).}}</span>
								</div>
							</div>
							<legend><i class="fas fa-sync-alt"></i> {{Synchro WLED}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Suivre un WLED (adresse)}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="wled_follow" list="list_goveeWled" placeholder="192.168.30.106">
									<datalist id="list_goveeWled">
										<?php
										try {
											foreach (govee::knownWled() as $address => $name) {
												echo '<option value="' . htmlspecialchars($address) . '">' . htmlspecialchars($name) . '</option>';
											}
										} catch (Exception $e) {
										}
										?>
									</datalist>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Adresse IP d'un WLED (port facultatif) : la lampe suit son allumage, sa luminosité et sa couleur fixe en une seconde, même sur un autre VLAN. Avec « Annoncer aux WLED », le WLED suivi reçoit aussi les changements de la lampe. Laisser vide pour ne suivre aucun WLED.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Recevoir la synchro UDP}}</label>
								<div class="col-sm-5">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="wled_recv">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{La lampe applique l'allumage, la luminosité et la couleur fixe que les WLED de ses groupes diffusent (« Send notifications » côté WLED). Même réseau que Jeedom seulement : la diffusion ne traverse pas les VLAN.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Annoncer aux WLED}}</label>
								<div class="col-sm-5">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="wled_send">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Chaque changement de la lampe (Jeedom, application Govee, voix) est diffusé aux WLED de ses groupes, et envoyé au WLED suivi.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Groupes}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="wled_groups" placeholder="1">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Numéros de 1 à 8, séparés par des virgules : les cases « Groups » de la page Sync des WLED.}}</span>
								</div>
							</div>
							<legend><i class="fas fa-mobile-alt"></i> {{Appli WLED}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Se présenter comme un WLED}}</label>
								<div class="col-sm-5">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="wled_api">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{La lampe répond comme un WLED sur le port ci-dessous : l'appli WLED la pilote, et chaque geste exécute les commandes de cet équipement. Démon requis. Accessible sans mot de passe depuis tout le réseau local.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Port}}</label>
								<div class="col-sm-5">
									<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="wled_api_port" placeholder="8180" min="1025" max="65535">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">
										<?php
										$jeedomIp = '';
										try {
											$jeedomIp = (string) network::getNetworkAccess('internal', 'ip');
										} catch (Exception $e) {
										}
										echo '{{Un port par lampe. Dans l\'appli WLED, ajoutez l\'appareil à la main avec l\'adresse}} <code>' . htmlspecialchars($jeedomIp !== '' ? $jeedomIp : 'IP-de-Jeedom') . ':port</code>. {{La recherche automatique de l\'appli ne garde pas le port : elle ne trouvera pas la lampe.}}';
										?>
									</span>
								</div>
							</div>
							<legend><i class="fas fa-info-circle"></i> {{Identité}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Modèle}}</label>
								<div class="col-sm-9"><span class="label label-info" id="span_goveeSku"></span> <span id="span_goveeProfile" class="label label-default"></span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Identifiant Govee}}</label>
								<div class="col-sm-9"><span class="eqLogicAttr" data-l1key="logicalId"></span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Micrologiciel}}</label>
								<div class="col-sm-9"><span id="span_goveeFirmware"></span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_goveeRefresh"><i class="fas fa-sync"></i> {{Relever maintenant}}</a>
									<span id="span_goveeStatus" style="margin-left:8px;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Effets}}</label>
								<div class="col-sm-9">
									<span id="span_goveeScenes"></span>
									<a class="btn btn-default btn-sm" id="bt_goveeScenes" style="margin-left:8px;"><i class="fas fa-magic"></i> {{Mettre à jour les effets}}</a>
									<span class="help-block" style="margin:4px 0 0 0;">{{Les effets de l'application Govee, lancés en local par la commande « Lancer un effet ». La liste vient de la bibliothèque publique de Govee.}}</span>
								</div>
							</div>
							<legend><i class="fas fa-code"></i> {{Dernier état renvoyé par la lampe}}</legend>
							<pre id="pre_goveeRaw" style="max-height:300px;overflow:auto;"></pre>
						</fieldset>
					</form>
				</div>
			</div>

			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:320px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:160px;">{{Valeur}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'govee', 'js', 'govee'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
