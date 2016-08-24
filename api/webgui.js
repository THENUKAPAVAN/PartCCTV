/*!
 * webgui.js
 * (c) 2016 m1ron0xFF
 * @license: CC BY-NC-SA 4.0
 */

function htmlspecialchars(str) {
 if (typeof(str) == "string") {
  str = str.replace(/&/g, "&amp;"); /* must do &amp; first */
  str = str.replace(/"/g, "&quot;");
/*   str = str.replace(/'/g, "&#039;"); */
  str = str.replace(/</g, "&lt;");
  str = str.replace(/>/g, "&gt;");
  }
 return str;
 }

$(function() {
//Cam Settings Modal
$(document).on("click", ".open-CamSettings", function () {
    var camId = $(this).data('id');
	$.ajax({
		url: '/api/1.0/camera/'+camId+'/',
		type: "get",
		dataType: "json",
		error: function(xhr) {
			console.log('Ошибка! '+xhr.status+' '+xhr.statusText); 
		},
		success: function(data) {
			$('#cam_settings_id').val(data[0].id);
			$('#cam_settings_name').val(data[0].title);
			$('#cam_settings_source').val(data[0].source);
			if(data[0].enabled==1) {
				$('#cam_settings_enabled').prop('checked', true);
			}		
		}
	});
});
	
// Progress Bar & Core Status
$.ajax({
    url: '/api/1.0/platform/status',
    type: "get",
    dataType: "json",
    error: function(xhr) {
        console.log('Ошибка! '+xhr.status+' '+xhr.statusText); 
    },	
    success: function(data) {
        $('#core_status_ajax').html('<div class="progress-bar progress-bar-success" role="progressbar" style="width: '+ (data.total_space - data.free_space)/data.total_space*100 +'%;">На '+data.path+' свободно '+data.free_space+'Гб из '+data.total_space+'Гб</div>');
        $('#core_pid').html('Core PID: '+ data.core_pid);
		$('#core_version').html('Version: '+ data.core_version);
    }
});

// Cam List
$.ajax({
    url: '/api/1.0/camera/list',
    type: "get",
    dataType: "json",
    error: function(xhr) {
        console.log('Ошибка! '+xhr.status+' '+xhr.statusText); 
    },	
    success: function(data) {
        drawCamList(data);
    }
});

function drawCamList(data) {

    var html = '';

    for (var i = 0; i < data.length; i++) {
        html += drawCamListRaw(data[i]);
    }

    $('#cam_list_ajax').html(html);
}

function drawCamListRaw(rawData) {

    if (rawData.enabled==0) {
        var error = '<div class="alert alert-warning">' +
                        '<b>Камера отключена!</b>' +
                    '</div>';
    } else if (rawData.enabled==1 && !rawData.pid ) {
        var error = '<div class="alert alert-danger">' +
                        '<b>Воркер не запущен, запись не производится!</b>' +
                    '</div>';    
    } else {
        var error = '';
    }

    return '<div class="col-md-12">' +
        '<div class="panel panel-success">' +
            '<div class="panel-heading">' +
                '<h6 class="panel-title text-warning"><b>'+rawData.title+'</b> <kbd>id'+rawData.id+'</kbd> <kbd>PID '+rawData.pid+'</kbd></h6>' +
            '</div>' +
            '<div class="panel-body">' +
                error +
                '<div class="btn-group btn-group-lg">' +
                    '<a data-toggle="modal" data-target="#cam_stream" data-source="'+rawData.source+'" class="btn btn-success"><span class="glyphicon glyphicon-play" aria-hidden="true"></span> Прямой эфир</a>' +				
                    '<a href="/archive/id'+rawData.id+'" class="btn btn-primary">Архив</a>' +				
                    '<a data-toggle="modal" data-target="#cam_settings" data-id="'+rawData.id+'" class="open-CamSettings btn btn-warning">Настройки</a>' +
                '</div>' +
            '</div>' +
        '</div>' +
    '</div>';          
}

// Core Settings Modal
$("#core_settings").on("show.bs.modal", function() {
$.ajax({
    url: '/api/1.0/platform/settings',
    type: "get",
    dataType: "json",
    error: function(xhr) {
        console.log('Ошибка! '+xhr.status+' '+xhr.statusText); 
    },	
    success: function(data) {
        drawSettingsModal(data);
    }
});

function drawSettingsModal(data) {
    //часть формы до полей, которые генерируем автоматически
    var html = '<form action="" method="post">' +
            '<fieldset>';

    //генерируем html-код полей с именами и значениями из поступивших данных 
    for (var i = 0; i < data.length; i++) {
        html += drawSettingsRaw(data[i]);
    }

    //часть формы после генерируемых полей
    html += '<input type="hidden" name="action" value="platform_settings">' +
        '<br>' +
        '<input class="btn btn-primary" type="submit" value="Сохранить">' +
        '</fieldset>' +
    '</form>';
    
    $('#core_settings_ajax').html(html);
}

function drawSettingsRaw(rawData) {
    return '<div class="control-group">' +
        '<label class="control-label" for="'+rawData.param+'">'+rawData.param+'</label>' +
        '<div class="controls">' +
            '<input type="text" id="'+rawData.param+'" name="'+rawData.param+'" value="'+htmlspecialchars(rawData.value)+'"  required class="form-control input-lg">' +
        '</div>' +
    '</div>';
}
});

// Core Log Modal
$("#core_log").on("show.bs.modal", function() {
$.ajax({
    type: "get", 
    url: "/api/1.0/platform/log", 
    dataType:"text", 
    error: function(xhr) {
        console.log('Ошибка! '+xhr.status+' '+xhr.statusText); 
    },
    success: function(a) {
        $('#core_log_ajax').html(a);
    }  
})});

// Cam Log Modal
$("#cam_log").on("show.bs.modal", function() {
$.ajax({
    type: "get", 
    url: "/api/1.0/camera/log", 
    dataType:"text", 
    error: function(xhr) {
        console.log('Ошибка! '+xhr.status+' '+xhr.statusText); 
    },
    success: function(a) {
        $('#cam_log_ajax').html(a);
    }  
})});
});