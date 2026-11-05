{
// Managed by Ownswitch installer (deploy/stages/60_cgrates.sh) - rendered from deploy/templates/cgrates.json.tpl
// CGRateS v0.10.x. DataDB = Redis (db index @@REDIS_CGR_DB@@), StorDB = MySQL database `cgrates`.
// Every listener is bound to 127.0.0.1 and must never be exposed publicly.

"general": {
	"node_id": "ownswitch-cgr1",
	"logger": "*syslog",
	"log_level": 6,
	"default_request_type": "*prepaid",
	"default_category": "call",
	"default_tenant": "@@CGRATES_TENANT@@",
	"default_timezone": "UTC",
	"rounding_decimals": 6,
	"connect_attempts": 5,
	"reconnects": -1
},

"listen": {
	"rpc_json": "@@CGR_JSONRPC_ADDR@@",
	"rpc_gob": "@@CGR_GOB_ADDR@@",
	"http": "@@CGR_HTTP_ADDR@@"
},

"data_db": {
	"db_type": "*redis",
	"db_host": "127.0.0.1",
	"db_port": 6379,
	"db_name": "@@REDIS_CGR_DB@@",
	"db_user": "default",
	"db_password": "@@REDIS_PASSWORD@@"
},

"stor_db": {
	"db_type": "*mysql",
	"db_host": "127.0.0.1",
	"db_port": 3306,
	"db_name": "cgrates",
	"db_user": "cgrates",
	"db_password": "@@CGRATES_DB_PASSWORD@@"
},

"rals": {
	"enabled": true
},

"schedulers": {
	"enabled": true
},

"cdrs": {
	"enabled": true,
	"store_cdrs": true,
	"rals_conns": ["*internal"]
},

"chargers": {
	"enabled": true,
	"attributes_conns": ["*internal"]
},

"attributes": {
	"enabled": true
},

"sessions": {
	"enabled": true,
	"listen_bijson": "@@CGR_BIJSON_ADDR@@",
	"rals_conns": ["*internal"],
	"cdrs_conns": ["*internal"],
	"chargers_conns": ["*internal"],
	"attributes_conns": ["*internal"],
	"debit_interval": "10s"
},

"apiers": {
	"enabled": true,
	"scheduler_conns": ["*internal"]
}

}
