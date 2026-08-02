SELECT
  (SELECT COUNT(*) FROM t_sys_mobile_permission
     WHERE permission_code='manage_campaigns' AND is_active=1)                    AS perm_created,
  (SELECT COUNT(*) FROM t_sys_user_role ur
     JOIN t_sys_role_mobile_permission rp ON rp.role_id=ur.role_id
     JOIN t_sys_mobile_permission p       ON p.id=rp.mobile_permission_id
     WHERE ur.user_id=92 AND p.permission_code='view_campaigns')                  AS adnan_can_view,
  (SELECT COUNT(*) FROM t_sys_user_role ur
     JOIN t_sys_role_mobile_permission rp ON rp.role_id=ur.role_id
     JOIN t_sys_mobile_permission p       ON p.id=rp.mobile_permission_id
     WHERE ur.user_id=92 AND p.permission_code='manage_campaigns')                AS adnan_can_send,
  (SELECT COUNT(*) FROM t_sys_role_permissions rp
     JOIN t_sys_user_role ur ON ur.role_id=rp.role_id
     WHERE ur.user_id=92 AND rp.permission_key='web_menu_campaigns' AND rp.is_allowed=1) AS adnan_menu_row,
  (SELECT COUNT(*) FROM t_sys_role_permissions rp
     JOIN t_sys_user_role ur ON ur.role_id=rp.role_id
     WHERE ur.user_id=92 AND rp.permission_key='account_read_only' AND rp.is_allowed=1)  AS still_read_only;
