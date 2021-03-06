<?php
App::uses('Auth', 'Component');

$isConfirmed = Travel::isConfirmed($travel['Travel']['state']);

if($isConfirmed) {
    $pretty_drivers_count = $travel['Travel']['drivers_sent_count'].' chofer';
    if($travel['Travel']['drivers_sent_count'] > 1) $pretty_drivers_count .= 'es';
}
?>

<div class="container">
<div class="row">
    <div class="col-md-6 col-md-offset-3"> 
        <div id="travel">
            <p>
                Tienes el siguiente viaje 
                <span style="color:<?php echo Travel::$STATE[$travel['Travel']['state']]['color']?>">
                    <b><?php echo Travel::$STATE[$travel['Travel']['state']]['label']?></b>
                </span>:
            </p>
            <?php echo $this->element('travel', array('actions'=>false))?>
            <?php if(!$isConfirmed):?>
                <a title="Edita este viaje" href="#!" class="edit-travel">&ndash; Editar este Viaje</a>    
                <br/>
                <br/>
                <?php echo $this->Html->link('Confirmar este Anuncio de Viaje 
                <div style="font-size:10pt;padding-left:50px;padding-right:50px">Estos datos serán enviados enseguida a algunos choferes que pudieran atenderte</div>', 
                    array('controller'=>'travels', 'action'=>'confirm/'.$travel['Travel']['id']), 
                    array('class'=>'btn btn-primary', 'style'=>'font-size:16pt;white-space: normal;', 'escape'=>false));?>
            <?php else:?>   
                <br/>
                <p class="text-info">
                    <?php if(AuthComponent::user('role') == 'regular'):?>
                    <b>Los datos de este viaje fueron eviados a <big><?php echo $pretty_drivers_count?></big></b>. Pronto serás contactado.

                    <?php else:?>
                    <b>Se encontaron <big><?php echo $pretty_drivers_count?></big></b> para notificar, pero son <b>choferes de prueba</b> porque eres un usuario <b><?php echo AuthComponent::user('role')?></b>.
                    <?php endif?>
                </p>
            <?php endif?>
        </div>
        <?php if(!$isConfirmed):?>
            <div id='travel-form' style="display:none">
                <legend>Edita los datos de este viaje antes de confirmar <div><a href="#!" class="cancel-edit-travel">&ndash; no editar este viaje</a></div></legend>
                <?php echo $this->element('travel_form', array('do_ajax' => true, 'form_action' => 'edit/' . $travel['Travel']['id'], 'intent'=>'edit')); ?>
                <br/>
            </div>
        <?php endif?>
        
        <br/>
        <?php echo $this->Html->link("<big>Ver todos mis Anuncios</big>", array('controller'=>'travels', 'action'=>'index'), array('escape'=>false))?>
    </div>    
</div>
</div>

<?php
$this->Html->script('jquery', array('inline' => false));
    
$this->Js->set('travel', $travel);
$this->Js->set('travels_preferences', Travel::$preferences);
$this->Js->set('localities', $localities);
echo $this->Js->writeBuffer(array('inline' => false));
?>

<?php if(!$isConfirmed):?>
    <?php echo $this->Html->script('travels_view', array('inline' => false));?>
<?php endif?>