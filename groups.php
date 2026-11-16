<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo); $errors=[]; $notice='';
$selected=(int)($_GET['g']??0);
$gid=$selected; $postedAction='';
function group_cover_upload(array $file): array {
    $dir=__DIR__.'/assets/uploads/groups/';
    if(!is_dir($dir) && !mkdir($dir,0755,true)) return ['ok'=>false,'error'=>'Impossible de créer le dossier des couvertures.'];
    return save_uploaded_media($file,['image/jpeg','image/png','image/webp'],'assets/uploads/groups/' === '' ? '' : $dir,1600,600);
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check()) $errors[]='Session expirée. Actualise la page.';
 else {
  $action=$_POST['action']??''; $postedAction=$action; $gid=(int)($_POST['group_id']??$selected);
  if($action==='create'){
   $name=trim((string)($_POST['name']??''));$desc=trim((string)($_POST['description']??''));$vis=($_POST['visibility']??'public')==='private'?'private':'public';
   if(mb_strlen($name)<3||mb_strlen($name)>70)$errors[]='Le nom doit contenir entre 3 et 70 caractères.';
   elseif(mb_strlen($desc)>500)$errors[]='Description trop longue.';
   else{$slug=substr(preg_replace('/[^a-z0-9]+/','-',strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name))),0,70).'-'.bin2hex(random_bytes(3));$pdo->beginTransaction();$pdo->prepare('INSERT INTO groups_clash(name,slug,description,visibility,owner_id) VALUES(?,?,?,?,?)')->execute([$name,$slug,$desc,$vis,$me['id']]);$gid=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO group_members(group_id,user_id,role,status) VALUES(?,?,'boss','active')")->execute([$gid,$me['id']]);$pdo->commit();redirect('groups.php?g='.$gid);}
  } elseif(in_array($action,['join','leave','post','settings','cover','approve','role','remove_member','react'],true)){
   $q=$pdo->prepare('SELECT * FROM groups_clash WHERE id=?');$q->execute([$gid]);$g=$q->fetch();
   if(!$g)$errors[]='Clan introuvable.';
   else{
    $q=$pdo->prepare("SELECT role FROM group_members WHERE group_id=? AND user_id=? AND status='active'");$q->execute([$gid,$me['id']]);$myRole=$q->fetchColumn();
    if($action==='join'){
     if($g['visibility']==='private'){$pdo->prepare("INSERT INTO group_members(group_id,user_id,role,status) VALUES(?,?,'clasher','pending') ON DUPLICATE KEY UPDATE status=IF(status='active','active','pending')")->execute([$gid,$me['id']]);$notice='Demande envoyée au clan privé.';}
     else{$pdo->prepare("INSERT IGNORE INTO group_members(group_id,user_id,role,status) VALUES(?,?,'clasher','active')")->execute([$gid,$me['id']]);$notice='Tu as rejoint le clan.';}
    }elseif($action==='leave'){
     if($myRole==='boss')$errors[]='Le Boss doit transférer la direction avant de quitter le clan.';elseif($myRole){$pdo->prepare('DELETE FROM group_members WHERE group_id=? AND user_id=?')->execute([$gid,$me['id']]);$notice='Tu as quitté le clan.';}
    }elseif($action==='post'){
     $content=trim((string)($_POST['content']??''));
     if(!$myRole)$errors[]='Tu dois être membre actif pour provoquer dans ce clan.';
     elseif(mb_strlen($content)>2000)$errors[]='La provocation ne peut pas dépasser 2 000 caractères.';
     else{
      $uploads=[];$totalBytes=0;$uploadErrors=[];
      if(isset($_FILES['media']) && is_array($_FILES['media']['name'])){
       $fileCount=count($_FILES['media']['name']);
       if($fileCount>MAX_MEDIA_PER_POST)$uploadErrors[]='Maximum '.MAX_MEDIA_PER_POST.' photos ou vidéos par publication.';
       $uploadDir=__DIR__.'/assets/uploads/group-posts/';
       if(!is_dir($uploadDir) && !mkdir($uploadDir,0755,true))$uploadErrors[]='Impossible de préparer le dossier des médias.';
       if(!$uploadErrors){
        for($i=0;$i<min($fileCount,MAX_MEDIA_PER_POST);$i++){
         if((int)($_FILES['media']['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
         $size=(int)($_FILES['media']['size'][$i]??0);$totalBytes+=$size;
         if($totalBytes>MAX_POST_TOTAL_SIZE){$uploadErrors[]='Les médias dépassent la limite totale de '.(int)(MAX_POST_TOTAL_SIZE/1048576).' Mo.';break;}
         $one=['name'=>$_FILES['media']['name'][$i]??'','type'=>$_FILES['media']['type'][$i]??'','tmp_name'=>$_FILES['media']['tmp_name'][$i]??'','error'=>$_FILES['media']['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$size];
         $up=save_uploaded_media($one,array_merge(ALLOWED_IMAGE_TYPES,ALLOWED_VIDEO_TYPES),$uploadDir,1600,1600);
         if(!$up['ok']){$uploadErrors[]=$up['error'];break;}
         $uploads[]=['path'=>'assets/uploads/group-posts/'.$up['filename'],'type'=>$up['type']];
        }
       }
      }
      if($content==='' && !$uploads)$uploadErrors[]='Écris une provocation ou ajoute au moins une photo/vidéo.';
      if($uploadErrors){foreach($uploads as $u)@unlink(__DIR__.'/'.$u['path']);$errors=array_merge($errors,$uploadErrors);}
      else{
       try{$pdo->beginTransaction();$pdo->prepare('INSERT INTO group_posts(group_id,user_id,content,post_type) VALUES(?,?,?,\'clash\')')->execute([$gid,$me['id'],$content]);$newPost=(int)$pdo->lastInsertId();
        if($uploads){$ins=$pdo->prepare('INSERT INTO group_post_media(group_post_id,file_path,media_type,position) VALUES(?,?,?,?)');foreach($uploads as $idx=>$u)$ins->execute([$newPost,$u['path'],$u['type'],$idx]);}
        $pdo->commit();try{notify_mentions($pdo,$content,(int)$me['id'],null,(int)$gid);}catch(Throwable $mentionError){error_log('[CLASH] mention clan: '.$mentionError->getMessage());}$notice='Provocation publiée dans le clan.';
       }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($uploads as $u)@unlink(__DIR__.'/'.$u['path']);throw $e;}
      }
     }
    }elseif($action==='settings'){
     if($myRole!=='boss'&&$myRole!=='administrator')$errors[]='Seuls le Boss et les Administrateurs peuvent modifier les paramètres.';
     else{$name=trim((string)($_POST['name']??''));$desc=trim((string)($_POST['description']??''));$vis=($_POST['visibility']??'public')==='private'?'private':'public';if(mb_strlen($name)<3||mb_strlen($name)>70||mb_strlen($desc)>500)$errors[]='Vérifie le nom et la description du clan.';else{$pdo->prepare('UPDATE groups_clash SET name=?,description=?,visibility=? WHERE id=?')->execute([$name,$desc,$vis,$gid]);$notice='Paramètres du clan enregistrés.';}}
    }elseif($action==='cover'){
     if($myRole!=='boss'&&$myRole!=='administrator')$errors[]='Tu ne peux pas modifier la couverture.';
     elseif(empty($_FILES['cover']['name']))$errors[]='Choisis une image de couverture.';
     else{$up=group_cover_upload($_FILES['cover']);if(!$up['ok'])$errors[]=$up['error'];else{$old=$g['cover_image']??'';$rel='assets/uploads/groups/'.$up['filename'];$pdo->prepare('UPDATE groups_clash SET cover_image=? WHERE id=?')->execute([$rel,$gid]);if($old&&strpos($old,'assets/uploads/groups/')===0&&is_file(__DIR__.'/'.$old))@unlink(__DIR__.'/'.$old);$notice='Photo de couverture mise à jour.';}}
    }elseif(in_array($action,['approve','role','remove_member'],true)){
     if(!in_array($myRole,['boss','administrator'],true))$errors[]='Droits insuffisants pour gérer les membres.';
     else{$uid=(int)($_POST['user_id']??0);$q=$pdo->prepare('SELECT role,status FROM group_members WHERE group_id=? AND user_id=?');$q->execute([$gid,$uid]);$target=$q->fetch();if(!$target||$target['role']==='boss')$errors[]='Ce membre ne peut pas être modifié.';elseif($action==='approve'){$pdo->prepare("UPDATE group_members SET status='active' WHERE group_id=? AND user_id=? AND status='pending'")->execute([$gid,$uid]);$notice='Demande acceptée.';}elseif($action==='remove_member'){$pdo->prepare('DELETE FROM group_members WHERE group_id=? AND user_id=?')->execute([$gid,$uid]);$notice='Membre retiré du clan.';}else{$role=$_POST['role']??'clasher';if($myRole!=='boss'&&$role==='administrator')$errors[]='Seul le Boss peut nommer un Administrateur.';elseif(!in_array($role,['administrator','moderator','enforcer','clasher'],true))$errors[]='Rôle invalide.';else{$pdo->prepare('UPDATE group_members SET role=? WHERE group_id=? AND user_id=?')->execute([$role,$gid,$uid]);$notice='Rôle du membre mis à jour.';}}}
    }
    elseif($action==='react'){
     if(!$myRole)$errors[]='Rejoins le clan pour réagir.';else{$pid=(int)($_POST['post_id']??0);$reaction=$_POST['reaction']??'angry';$allowed=['caca','vomit','unlike','cry','angry','alien','clown','fuck'];if(!in_array($reaction,$allowed,true))$reaction='angry';$q=$pdo->prepare('SELECT id FROM group_posts WHERE id=? AND group_id=?');$q->execute([$pid,$gid]);if($q->fetch()){$pdo->prepare('INSERT INTO group_post_reactions(group_post_id,user_id,type) VALUES(?,?,?) ON DUPLICATE KEY UPDATE type=VALUES(type)')->execute([$pid,$me['id'],$reaction]);$notice='Réaction ajoutée.';}}
    }
   }
  }
 }
}
if($notice && !$errors){
 flash('success',$notice);
 redirect($postedAction==='leave' ? 'groups.php' : 'groups.php'.($gid ? '?g='.$gid : ''));
}
$group=null;$mine=null;$myRole=null;$members=[];$posts=[];$activeCount=0;
$tab=($_GET['tab']??'')==='mine'?'mine':'discover';
if($selected){
 $q=$pdo->prepare('SELECT g.*,u.display_name owner_name,u.username owner_username FROM groups_clash g JOIN users u ON u.id=g.owner_id WHERE g.id=?');$q->execute([$selected]);$group=$q->fetch();
 if($group){
  $q=$pdo->prepare('SELECT role,status FROM group_members WHERE group_id=? AND user_id=?');$q->execute([$selected,$me['id']]);$mine=$q->fetch();
  $myRole=($mine&&$mine['status']==='active')?$mine['role']:null;
  $q=$pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id=? AND status='active'");$q->execute([$selected]);$activeCount=(int)$q->fetchColumn();
  if($myRole){
   $q=$pdo->prepare("SELECT u.id,u.username,u.display_name,u.profile_photo,m.role,m.status FROM group_members m JOIN users u ON u.id=m.user_id WHERE m.group_id=? ORDER BY FIELD(m.role,'boss','administrator','moderator','enforcer','clasher'),m.joined_at");$q->execute([$selected]);$members=$q->fetchAll();
   $q=$pdo->prepare("SELECT p.*,u.username,u.display_name,u.profile_photo,(SELECT type FROM group_post_reactions r WHERE r.group_post_id=p.id AND r.user_id=?) my_reaction FROM group_posts p JOIN users u ON u.id=p.user_id WHERE p.group_id=? AND p.user_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id=?) ORDER BY p.created_at DESC,p.id DESC LIMIT 50");
   $q->execute([$me['id'],$selected,$me['id']]);$posts=group_posts_with_counts($pdo,$q->fetchAll(),(int)$me['id']);
  }
 }
}
// Clans que je gère (Boss / Administrateur) — widget de la colonne de droite
$q=$pdo->prepare("SELECT g.id,g.name,g.cover_image,m.role,(SELECT COUNT(*) FROM group_members x WHERE x.group_id=g.id AND x.status='active') member_count FROM group_members m JOIN groups_clash g ON g.id=m.group_id WHERE m.user_id=? AND m.status='active' AND m.role IN ('boss','administrator') ORDER BY FIELD(m.role,'boss','administrator'),g.name LIMIT 20");
$q->execute([$me['id']]);$managedGroups=$q->fetchAll();
$groups=[];
if(!$group){
 $where=$tab==='mine'?"WHERE EXISTS(SELECT 1 FROM group_members m WHERE m.group_id=g.id AND m.user_id=? AND m.status IN ('active','pending'))":"WHERE (g.visibility='public' OR EXISTS(SELECT 1 FROM group_members m WHERE m.group_id=g.id AND m.user_id=? AND m.status='active'))";
 $q=$pdo->prepare("SELECT g.*,(SELECT COUNT(*) FROM group_members m WHERE m.group_id=g.id AND m.status='active') member_count,(SELECT m.status FROM group_members m WHERE m.group_id=g.id AND m.user_id=?) my_status,(SELECT m.role FROM group_members m WHERE m.group_id=g.id AND m.user_id=?) my_role FROM groups_clash g $where ORDER BY member_count DESC,g.created_at DESC LIMIT 60");
 $q->execute([$me['id'],$me['id'],$me['id']]);$groups=$q->fetchAll();
}
$canManage=in_array($myRole,['boss','administrator'],true);
$roleLabels=['boss'=>'Boss','administrator'=>'Admin','moderator'=>'Modo','enforcer'=>'Enforcer','clasher'=>'Clasher'];
$pageTitle=$group?$group['name']:'Clans';$navActive='groups';
require __DIR__.'/includes/header.php';?>
<?php if($group): ?><?=page_head($group['name'],BASE_URL.'/groups.php','fa-shield-halved')?><?php else: ?><?=page_head('Clans',null,'fa-users-rectangle')?><?php endif;?>
<section class="groups-page">
 <?php foreach($errors as $e):?><div class="alert alert--error"><?=clean($e)?></div><?php endforeach;?>

 <?php if(!$group):?>
  <div class="clan-chips" role="tablist">
   <a class="clan-chip <?=$tab==='discover'?'is-active':''?>" href="<?=BASE_URL?>/groups.php">Découvrir</a>
   <a class="clan-chip <?=$tab==='mine'?'is-active':''?>" href="<?=BASE_URL?>/groups.php?tab=mine">Mes clans</a>
   <button type="button" class="clan-chip clan-chip--cta" data-open-dialog="#clanCreateDialog"><i class="fa-solid fa-plus"></i> Créer</button>
  </div>
  <div class="clan-inline"><?php include __DIR__.'/includes/clan_widgets.php';?></div>
  <?php if(!$groups):?>
   <div class="empty-state"><i class="fa-solid fa-shield-halved"></i><h2><?=$tab==='mine'?'Tu n’as rejoint aucun clan':'Aucun clan pour le moment'?></h2><p>Crée le premier et rassemble tes Clashers.</p><button type="button" class="btn btn--primary" data-open-dialog="#clanCreateDialog"><i class="fa-solid fa-plus"></i> Créer un clan</button></div>
  <?php else:?>
  <div class="groups-grid">
   <?php foreach($groups as $g):$st=$g['my_status'];?>
    <article class="group-card">
     <a class="group-card__cover" href="<?=BASE_URL?>/groups.php?g=<?=$g['id']?>"><?php if(!empty($g['cover_image'])):?><img src="<?=asset($g['cover_image'])?>" alt="Couverture de <?=clean($g['name'])?>" loading="lazy"><?php else:?><span><i class="fa-solid fa-shield-halved"></i></span><?php endif;?></a>
     <div class="group-card__body">
      <div class="group-card__top"><h3><a href="<?=BASE_URL?>/groups.php?g=<?=$g['id']?>"><?=clean($g['name'])?></a></h3><span class="pill"><i class="fa-solid <?=$g['visibility']==='public'?'fa-earth-europe':'fa-lock'?>"></i> <?=$g['visibility']==='public'?'Public':'Privé'?></span></div>
      <p><?=clean(trim((string)$g['description'])!==''?(string)$g['description']:'Aucune description.')?></p>
      <div class="group-card__foot">
       <small class="muted"><i class="fa-solid fa-users"></i> <?=(int)$g['member_count']?> membre<?=(int)$g['member_count']>1?'s':''?></small>
       <?php if($st==='active'):?><a class="btn btn--outline btn--small" href="<?=BASE_URL?>/groups.php?g=<?=$g['id']?>">Ouvrir</a>
       <?php elseif($st==='pending'):?><span class="pill">Demande envoyée</span>
       <?php else:?><form method="post"><input type="hidden" name="csrf_token" value="<?=csrf_token()?>"><input type="hidden" name="action" value="join"><input type="hidden" name="group_id" value="<?=(int)$g['id']?>"><button class="btn btn--primary btn--small"><?=$g['visibility']==='private'?'Demander':'Rejoindre'?></button></form><?php endif;?>
      </div>
     </div>
    </article>
   <?php endforeach;?>
  </div>
  <?php endif;?>

 <?php else:?>
  <header class="clan-hero">
   <div class="clan-hero__cover"><?php if(!empty($group['cover_image'])):?><img src="<?=asset($group['cover_image'])?>" alt="Couverture du clan"><?php else:?><div class="clan-hero__empty"><i class="fa-solid fa-shield-halved"></i></div><?php endif;?></div>
   <div class="clan-hero__bar">
    <span class="clan-hero__avatar" aria-hidden="true"><?=clean(mb_strtoupper(mb_substr($group['name'],0,1)))?></span>
    <div class="clan-hero__actions">
     <?php if(!$myRole&&!empty($mine)&&$mine['status']==='pending'):?><span class="pill">Demande en attente</span>
     <?php elseif(!$myRole):?><form method="post"><input type="hidden" name="csrf_token" value="<?=csrf_token()?>"><input type="hidden" name="action" value="join"><input type="hidden" name="group_id" value="<?=$selected?>"><button class="btn btn--primary"><i class="fa-solid fa-door-open"></i> <?=$group['visibility']==='private'?'Demander à rejoindre':'Rejoindre'?></button></form>
     <?php else:?>
      <?php if($canManage):?><button type="button" class="btn btn--outline btn--small" data-toggle-target="#clanSettings"><i class="fa-solid fa-gear"></i> Gérer</button><?php endif;?>
      <?php if($myRole!=='boss'):?><form method="post" data-confirm="Quitter ce clan ?"><input type="hidden" name="csrf_token" value="<?=csrf_token()?>"><input type="hidden" name="action" value="leave"><input type="hidden" name="group_id" value="<?=$selected?>"><button class="btn btn--outline btn--small">Quitter</button></form><?php endif;?>
     <?php endif;?>
    </div>
   </div>
   <div class="clan-hero__info">
    <h2><?=clean($group['name'])?></h2>
    <?php if(trim((string)$group['description'])!==''):?><p class="clan-hero__desc"><?=nl2br(clean($group['description']))?></p><?php endif;?>
    <p class="clan-hero__meta"><span><i class="fa-solid <?=$group['visibility']==='public'?'fa-earth-europe':'fa-lock'?>"></i> <?=$group['visibility']==='public'?'Clan public':'Clan privé'?></span><span><i class="fa-solid fa-users"></i> <strong><?=$activeCount?></strong> membre<?=$activeCount>1?'s':''?></span><span><i class="fa-solid fa-crown"></i> Boss : <a href="<?=BASE_URL?>/profile.php?u=<?=urlencode($group['owner_username'])?>"><?=clean($group['owner_name'])?></a></span></p>
   </div>
  </header>

  <?php if($myRole):?>
   <?php if($canManage):?>
   <section class="clan-settings" id="clanSettings" hidden>
    <h3><i class="fa-solid fa-gear"></i> Paramètres du clan</h3>
    <form method="post" class="group-settings"><input type="hidden" name="csrf_token" value="<?=csrf_token()?>"><input type="hidden" name="action" value="settings"><input type="hidden" name="group_id" value="<?=$selected?>">
     <label>Nom<input name="name" maxlength="70" value="<?=clean($group['name'])?>" required></label>
     <label>Description<textarea name="description" maxlength="500" rows="3"><?=clean($group['description'])?></textarea></label>
     <label>Confidentialité<select name="visibility"><option value="public" <?=$group['visibility']==='public'?'selected':''?>>Public</option><option value="private" <?=$group['visibility']==='private'?'selected':''?>>Privé</option></select></label>
     <button class="btn btn--primary btn--small">Enregistrer</button>
    </form>
    <form method="post" enctype="multipart/form-data" class="group-settings"><input type="hidden" name="csrf_token" value="<?=csrf_token()?>"><input type="hidden" name="action" value="cover"><input type="hidden" name="group_id" value="<?=$selected?>">
     <label>Photo de couverture<input type="file" name="cover" accept="image/jpeg,image/png,image/webp" required></label>
     <small class="muted">JPG, PNG ou WebP. Image horizontale recommandée.</small>
     <button class="btn btn--outline btn--small">Mettre à jour la couverture</button>
    </form>
   </section>
   <?php endif;?>

   <form method="post" enctype="multipart/form-data" class="clan-composer" id="clanComposer">
    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>"><input type="hidden" name="action" value="post"><input type="hidden" name="group_id" value="<?=$selected?>">
    <img src="<?=media_url($me['profile_photo'])?>" alt="">
    <div class="clan-composer__main">
     <textarea name="content" maxlength="2000" rows="1" placeholder="Lance une provocation à ton clan…" aria-label="Nouveau Clash du clan"></textarea>
     <div class="media-preview" id="clanMediaPreview"></div>
     <div class="clan-composer__bar">
      <label class="icon-btn clan-composer__attach" for="clanMediaInput" title="Ajouter des photos ou vidéos (jusqu’à 10 fichiers · 25 Mo chacun)" aria-label="Ajouter des photos ou vidéos"><i class="fa-regular fa-image"></i></label>
      <input type="file" id="clanMediaInput" name="media[]" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime" multiple hidden>
      <span class="clan-composer__info muted" id="clanMediaCount" aria-live="polite"></span>
      <span class="clan-composer__count muted" data-count-for="content" hidden>0 / 2000</span>
      <button class="btn btn--primary btn--small clan-composer__send" disabled><i class="fa-solid fa-bolt"></i> Provoquer</button>
     </div>
    </div>
   </form>

   <div class="clan-inline"><?php include __DIR__.'/includes/clan_widgets.php';?></div>

   <div class="feed group-feed__list" id="clanFeed">
    <?php foreach($posts as $post){ include __DIR__.'/includes/group_post_card.php'; } ?>
   </div>
   <?php if(!$posts):?><div class="empty-state"><i class="fa-solid fa-explosion"></i><h2>Aucun Clash dans ce clan</h2><p>Lance la première provocation !</p></div><?php endif;?>
  <?php else:?>
   <p class="muted group-private-note"><i class="fa-solid fa-lock"></i><br>Rejoins ce clan pour voir les Clashs et les membres.</p>
  <?php endif;?>
 <?php endif;?>
</section>

<dialog id="clanCreateDialog" class="dialog dialog--small clan-dialog" aria-labelledby="clanCreateTitle">
 <form method="post" class="clan-create">
  <input type="hidden" name="csrf_token" value="<?=csrf_token()?>"><input type="hidden" name="action" value="create">
  <div class="clan-create__head"><h2 id="clanCreateTitle"><i class="fa-solid fa-shield-halved"></i> Nouveau clan</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Fermer"><i class="fa-solid fa-xmark"></i></button></div>
  <input name="name" maxlength="70" minlength="3" required placeholder="Nom du clan" aria-label="Nom du clan" autocomplete="off">
  <div class="clan-create__vis" role="radiogroup" aria-label="Confidentialité">
   <label><input type="radio" name="visibility" value="public" checked><span><i class="fa-solid fa-earth-europe"></i> Public</span></label>
   <label><input type="radio" name="visibility" value="private"><span><i class="fa-solid fa-lock"></i> Privé</span></label>
  </div>
  <details class="clan-create__more"><summary>Ajouter une description</summary><textarea name="description" maxlength="500" rows="2" placeholder="Présente ton clan…" aria-label="Description"></textarea></details>
  <button class="btn btn--primary btn--block" type="submit"><i class="fa-solid fa-plus"></i> Créer le clan</button>
 </form>
</dialog>
<?php require __DIR__.'/includes/footer.php';?>
