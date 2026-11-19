/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

function goveeEl(_id) {
  return document.getElementById(_id)
}

/* Ce qui vient d'une lampe ou du serveur est du texte, jamais du balisage. */
function goveeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

function goveeText(_id, _text) {
  var el = goveeEl(_id)
  if (el !== null) { el.textContent = (_text === null || _text === undefined) ? '' : String(_text) }
}

function goveeAjax(_action, _data, _success, _error) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/govee/core/ajax/govee.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (error) {
      if (typeof _error === 'function') { _error(error); return }
      domUtils.handleAjaxError(error)
    },
    success: function (result) {
      if (result.state !== 'ok') {
        if (typeof _error === 'function') { _error(result); return }
        jeedomUtils.showAlert({ message: goveeEscape(result.result), level: 'danger' })
        return
      }
      _success(result)
    }
  })
}

function goveeCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

/* ============================================================== RECHERCHE */

window.goveeFound = []

function goveeSearchStatus(_level, _html) {
  var status = goveeEl('div_goveeSearchStatus')
  status.style.display = ''
  status.className = 'alert alert-' + _level
  status.innerHTML = _html
}

function goveeScanOpen() {
  goveeEl('div_goveeSearch').style.display = ''
  goveeScan()
}

function goveeScan() {
  var ips = goveeEl('in_goveeIps').value
  goveeEl('div_goveeSearchResults').innerHTML = ''
  goveeSearchStatus('info', '<i class="fas fa-spinner fa-spin"></i> {{Recherche en cours (3 secondes)…}}')
  goveeAjax('scan', { ips: ips }, function (result) {
    var devices = result.result.devices
    window.goveeFound = devices
    if (devices.length === 0) {
      goveeSearchStatus('warning', '{{Aucune lampe n\'a répondu. Vérifiez que « LAN Control » est activé dans l\'application Govee, puis, si la lampe est sur un autre réseau ou VLAN, saisissez son adresse IP ci-dessus : la recherche automatique ne le traverse pas, et le pare-feu doit laisser passer les ports UDP 4001 à 4003.}}')
      return
    }
    goveeSearchStatus('success', devices.length + ' {{lampe(s) trouvée(s).}}')
    var html = '<table class="table table-condensed table-bordered"><thead><tr><th>{{Modèle}}</th><th>{{Adresse IP}}</th><th>{{Identifiant}}</th><th>{{Micrologiciel (Wi-Fi / BLE)}}</th><th></th></tr></thead><tbody>'
    devices.forEach(function (_d, _i) {
      html += '<tr><td>' + goveeEscape(_d.sku) + '</td><td>' + goveeEscape(_d.ip) + '</td><td><small>' + goveeEscape(_d.device) + '</small></td>'
      html += '<td>' + goveeEscape(_d.wifi_soft) + ' / ' + goveeEscape(_d.ble_soft) + '</td><td>'
      if (_d.known) {
        html += '<span class="label label-default">' + goveeEscape(_d.known) + '</span> '
        html += '<a class="btn btn-default btn-xs goveeCreate" data-index="' + _i + '"><i class="fas fa-sync"></i> {{Mettre à jour}}</a>'
      } else {
        html += '<a class="btn btn-success btn-xs goveeCreate" data-index="' + _i + '"><i class="fas fa-plus-circle"></i> {{Créer}}</a>'
      }
      html += '</td></tr>'
    })
    goveeEl('div_goveeSearchResults').innerHTML = html + '</tbody></table>'
  }, function (error) {
    goveeSearchStatus('danger', goveeEscape(error.result || error.responseText || '{{Erreur}}'))
  })
}

function goveeCreate(_index) {
  var device = window.goveeFound[_index]
  if (!device) { return }
  goveeAjax('create', { devices: JSON.stringify([device]) }, function (result) {
    var id = result.result.created[0]
    jeedomUtils.loadPage('index.php?v=d&m=govee&p=govee' + (id ? '&id=' + id : ''))
  })
}

/* ============================================================ ÉQUIPEMENT */

function goveeRefresh() {
  var id = goveeCurrentId()
  if (id === '') { return }
  goveeText('span_goveeStatus', '{{Relevé…}}')
  goveeAjax('refresh', { id: id }, function () {
    setTimeout(function () { goveeLoadData(id) }, 1200)
  })
}

function goveeLoadData(_id) {
  goveeAjax('data', { id: _id }, function (result) {
    var data = result.result
    if (String(data.id) !== String(goveeCurrentId())) { return }
    goveeText('pre_goveeRaw', data.status ? JSON.stringify(data.status, null, 2) : '{{Aucun état reçu pour le moment.}}')
    goveeText('span_goveeProfile', data.uplighter ? '{{Uplighter, trois zones}}' : '{{Lampe simple}}')
    goveeText('span_goveeScenes', data.scenes > 0 ? data.scenes + ' {{effets disponibles}}' : '{{Aucun effet connu pour ce modèle}}')
    var when = data.status_at > 0 ? new Date(data.status_at * 1000).toLocaleString() : ''
    goveeText('span_goveeStatus', (data.daemon ? '{{Démon en marche}}' : '{{Démon arrêté}}') + (when ? ' · {{dernier état}} ' + when : ''))
  })
}

function goveeScenes() {
  var id = goveeCurrentId()
  if (id === '') { return }
  goveeText('span_goveeScenes', '{{Téléchargement…}}')
  goveeAjax('scenes', { id: id }, function (result) {
    jeedomUtils.showAlert({ message: result.result.count + ' {{effets. La liste de la commande « Lancer un effet » est à jour.}}', level: 'success' })
    goveeLoadData(id)
  }, function (error) {
    goveeText('span_goveeScenes', '')
    jeedomUtils.showAlert({ message: goveeEscape(error.result || error.responseText || '{{Erreur}}'), level: 'danger' })
  })
}

/* ===================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  var conf = isset(_eqLogic.configuration) ? _eqLogic.configuration : {}
  goveeText('span_goveeSku', conf.sku || '?')
  goveeText('span_goveeFirmware', (conf.wifi_soft ? 'Wi-Fi ' + conf.wifi_soft : '') + (conf.ble_soft ? ' · BLE ' + conf.ble_soft : ''))
  goveeText('pre_goveeRaw', '')
  goveeText('span_goveeStatus', '')
  goveeText('span_goveeProfile', '')
  goveeText('span_goveeScenes', '')
  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    goveeLoadData(String(_eqLogic.id))
  }
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="unite" style="margin-left:8px;opacity:.7;"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'
  /* Ligne créée en DOM : insertAdjacentHTML sur une table crée un <tbody> par
     insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ============================================================== ÉCOUTEURS */

/* Pages chargées en ajax : ce script est réexécuté à chaque visite. Les
   gestionnaires sont redéfinis à chaque fois ; l'écouteur, lui, n'est posé
   qu'une fois sur le document. */
window.goveeHandlers = {
  click: function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var create = target.closest('.goveeCreate')
    if (create !== null) {
      _event.preventDefault()
      goveeCreate(parseInt(create.getAttribute('data-index'), 10))
      return
    }
    var actions = {
      bt_goveeScan: goveeScanOpen,
      bt_goveeScanGo: goveeScan,
      bt_goveeSearchClose: function () { goveeEl('div_goveeSearch').style.display = 'none' },
      bt_goveeRefresh: goveeRefresh,
      bt_goveeScenes: goveeScenes
    }
    for (var id in actions) {
      if (target.closest('#' + id) !== null) {
        _event.preventDefault()
        actions[id]()
        return
      }
    }
  },
  keydown: function (_event) {
    if (_event.key === 'Enter' && _event.target && _event.target.id === 'in_goveeIps') {
      _event.preventDefault()
      goveeScan()
    }
  }
}

if (!window.goveeListening) {
  window.goveeListening = true
  ;['click', 'keydown'].forEach(function (_type) {
    document.addEventListener(_type, function (_event) {
      if (window.goveeHandlers && typeof window.goveeHandlers[_type] === 'function') {
        window.goveeHandlers[_type](_event)
      }
    })
  })
}
