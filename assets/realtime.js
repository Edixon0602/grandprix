/* GRANDPRIX V7.1 · WebSocket privado sin polling ni long-polling. */
(()=>{
'use strict';

function connect(config,handlers={}){
  const status=typeof handlers.status==='function'?handlers.status:()=>{};
  if(!config||!config.enabled){status('disabled');return ()=>{}}
  if(config.provider!=='pusher'||!window.Pusher){status('unavailable');return ()=>{}}

  let closed=false;
  const csrf=(window.GRANDPRIX&&window.GRANDPRIX.csrf)||'';
  const pusher=new window.Pusher(config.key,{
    cluster:config.cluster,
    forceTLS:true,
    enabledTransports:['ws'],
    enableStats:false,
    channelAuthorization:{
      endpoint:config.authEndpoint,
      transport:'ajax',
      headers:{'X-CSRF-Token':csrf,'X-Requested-With':'XMLHttpRequest'}
    }
  });

  pusher.connection.bind('state_change',change=>{
    if(!closed)status(change.current||'connecting');
  });
  pusher.connection.bind('error',error=>{
    if(!closed&&typeof handlers.error==='function')handlers.error(error);
  });

  const channel=pusher.subscribe(config.channel);
  channel.bind('pusher:subscription_succeeded',()=>status('connected'));
  channel.bind('pusher:subscription_error',error=>{
    status('error');if(typeof handlers.error==='function')handlers.error(error);
  });
  channel.bind('gps-position',payload=>{
    if(!closed&&typeof handlers.position==='function')handlers.position(payload||{});
  });
  channel.bind('gps-event',payload=>{
    if(!closed&&typeof handlers.event==='function')handlers.event(payload||{});
  });
  channel.bind('gps-command-status',payload=>{
    if(!closed&&typeof handlers.command==='function')handlers.command(payload||{});
  });

  return ()=>{
    if(closed)return;closed=true;
    try{channel.unbind_all();pusher.unsubscribe(config.channel);pusher.disconnect()}catch(_){}
  };
}

window.GrandprixRealtime={connect};
})();
