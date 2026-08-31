
document.addEventListener('DOMContentLoaded', function(){
  const btn = document.querySelector('[data-menu-toggle]');
  const menu = document.querySelector('[data-mobile-menu]');
  if(btn && menu){
    btn.addEventListener('click', function(){
      menu.classList.toggle('open');
    });
  }
});
