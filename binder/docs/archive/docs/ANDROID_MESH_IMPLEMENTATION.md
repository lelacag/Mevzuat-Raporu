# Android Mesh Networking App - Implementation Guide

## Project Setup

### Prerequisites
- Android Studio Arctic Fox or later
- Kotlin 1.8+
- Min SDK: 26 (Android 8.0)
- Target SDK: 34 (Android 14)

### Dependencies (build.gradle)

```gradle
dependencies {
    // Core Android
    implementation 'androidx.core:core-ktx:1.12.0'
    implementation 'androidx.appcompat:appcompat:1.6.1'
    implementation 'com.google.android.material:material:1.11.0'
    
    // Lifecycle & ViewModel
    implementation 'androidx.lifecycle:lifecycle-viewmodel-ktx:2.7.0'
    implementation 'androidx.lifecycle:lifecycle-livedata-ktx:2.7.0'
    
    // Room Database
    implementation 'androidx.room:room-runtime:2.6.1'
    implementation 'androidx.room:room-ktx:2.6.1'
    kapt 'androidx.room:room-compiler:2.6.1'
    
    // Nearby Connections (Mesh Networking)
    implementation 'com.google.android.gms:play-services-nearby:19.1.0'
    
    // Retrofit (API)
    implementation 'com.squareup.retrofit2:retrofit:2.9.0'
    implementation 'com.squareup.retrofit2:converter-gson:2.9.0'
    
    // WorkManager (Background Sync)
    implementation 'androidx.work:work-runtime-ktx:2.9.0'
    
    // Kotlin Serialization
    implementation 'org.jetbrains.kotlinx:kotlinx-serialization-json:1.6.0'
    
    // Coroutines
    implementation 'org.jetbrains.kotlinx:kotlinx-coroutines-android:1.7.3'
    
    // Location
    implementation 'com.google.android.gms:play-services-location:21.1.0'
}
```

---

## Architecture

```
app/
├── data/
│   ├── local/
│   │   ├── database/
│   │   │   ├── AppDatabase.kt
│   │   │   ├── entities/
│   │   │   │   ├── LocalPost.kt
│   │   │   │   ├── LocalDistrict.kt
│   │   │   │   ├── LocalUser.kt
│   │   │   │   └── SyncQueue.kt
│   │   │   └── dao/
│   │   │       ├── PostDao.kt
│   │   │       ├── DistrictDao.kt
│   │   │       └── SyncQueueDao.kt
│   │   └── preferences/
│   │       └── AppPreferences.kt
│   ├── remote/
│   │   ├── api/
│   │   │   ├── ApiService.kt
│   │   │   └── dto/
│   │   │       ├── DistrictDto.kt
│   │   │       └── SyncDto.kt
│   │   └── RetrofitInstance.kt
│   └── repository/
│       ├── DistrictRepository.kt
│       ├── PostRepository.kt
│       └── SyncRepository.kt
├── domain/
│   ├── model/
│   │   ├── District.kt
│   │   ├── Post.kt
│   │   └── MeshMessage.kt
│   └── usecase/
│       ├── JoinDistrictUseCase.kt
│       └── SyncDataUseCase.kt
├── presentation/
│   ├── ui/
│   │   ├── main/
│   │   │   ├── MainActivity.kt
│   │   │   └── MainViewModel.kt
│   │   ├── districts/
│   │   │   ├── DistrictListActivity.kt
│   │   │   ├── DistrictFeedActivity.kt
│   │   │   └── DistrictViewModel.kt
│   │   └── mesh/
│   │       ├── MeshStatusFragment.kt
│   │       └── MeshViewModel.kt
│   └── adapter/
│       ├── DistrictAdapter.kt
│       └── PostAdapter.kt
├── service/
│   ├── mesh/
│   │   ├── MeshNetworkManager.kt
│   │   ├── MeshConnectionCallback.kt
│   │   └── MeshPayloadCallback.kt
│   ├── sync/
│   │   ├── SyncWorker.kt
│   │   └── SyncService.kt
│   └── location/
│       └── LocationService.kt
└── util/
    ├── NetworkUtil.kt
    ├── PermissionUtil.kt
    └── Constants.kt
```

---

## Core Implementation Files

### 1. AppDatabase.kt

```kotlin
package com.yourapp.textsocial.data.local.database

import androidx.room.Database
import androidx.room.RoomDatabase
import androidx.room.TypeConverters
import com.yourapp.textsocial.data.local.database.dao.*
import com.yourapp.textsocial.data.local.database.entities.*

@Database(
    entities = [
        LocalPost::class,
        LocalDistrict::class,
        LocalUser::class,
        SyncQueue::class,
        LocalLike::class,
        LocalComment::class
    ],
    version = 1,
    exportSchema = false
)
@TypeConverters(Converters::class)
abstract class AppDatabase : RoomDatabase() {
    abstract fun postDao(): PostDao
    abstract fun districtDao(): DistrictDao
    abstract fun userDao(): UserDao
    abstract fun syncQueueDao(): SyncQueueDao
    
    companion object {
        const val DATABASE_NAME = "textsocial_mesh.db"
    }
}
```

### 2. LocalPost.kt (Entity)

```kotlin
package com.yourapp.textsocial.data.local.database.entities

import androidx.room.Entity
import androidx.room.PrimaryKey
import androidx.room.ForeignKey
import java.util.UUID

@Entity(
    tableName = "local_posts",
    foreignKeys = [
        ForeignKey(
            entity = LocalDistrict::class,
            parentColumns = ["id"],
            childColumns = ["districtId"],
            onDelete = ForeignKey.CASCADE
        )
    ]
)
data class LocalPost(
    @PrimaryKey 
    val uuid: String = UUID.randomUUID().toString(),
    
    val districtId: Int,
    val userId: Int,
    val username: String,
    val content: String,
    val mediaUrl: String? = null,
    
    val createdAt: Long = System.currentTimeMillis(),
    val isSynced: Boolean = false,
    val syncedAt: Long? = null,
    val serverId: Int? = null,
    
    // Mesh propagation tracking
    val receivedVia: String? = null, // "mesh" or "server"
    val hopCount: Int = 0,
    val isLocal: Boolean = true
)
```

### 3. MeshNetworkManager.kt

```kotlin
package com.yourapp.textsocial.service.mesh

import android.content.Context
import com.google.android.gms.nearby.Nearby
import com.google.android.gms.nearby.connection.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.encodeToString
import kotlinx.serialization.decodeFromString

@Serializable
data class MeshMessage(
    val type: String, // "post", "like", "comment", "sync_request"
    val districtId: Int,
    val data: String, // JSON payload
    val fromUsername: String,
    val timestamp: Long = System.currentTimeMillis(),
    val hopCount: Int = 0,
    val messageId: String = UUID.randomUUID().toString()
)

class MeshNetworkManager(private val context: Context) {
    
    private val connectionsClient = Nearby.getConnectionsClient(context)
    private val strategy = Strategy.P2P_CLUSTER
    
    private val _connectedEndpoints = MutableStateFlow<Set<String>>(emptySet())
    val connectedEndpoints: StateFlow<Set<String>> = _connectedEndpoints
    
    private val _receivedMessages = MutableStateFlow<MeshMessage?>(null)
    val receivedMessages: StateFlow<MeshMessage?> = _receivedMessages
    
    private val seenMessages = mutableSetOf<String>() // For deduplication
    
    private val connectionLifecycleCallback = object : ConnectionLifecycleCallback() {
        override fun onConnectionInitiated(endpointId: String, info: ConnectionInfo) {
            // Auto-accept all connections within the district
            connectionsClient.acceptConnection(endpointId, payloadCallback)
        }
        
        override fun onConnectionResult(endpointId: String, result: ConnectionResolution) {
            if (result.status.isSuccess) {
                val current = _connectedEndpoints.value.toMutableSet()
                current.add(endpointId)
                _connectedEndpoints.value = current
                
                // Send sync request to new node
                sendSyncRequest(endpointId)
            }
        }
        
        override fun onDisconnected(endpointId: String) {
            val current = _connectedEndpoints.value.toMutableSet()
            current.remove(endpointId)
            _connectedEndpoints.value = current
        }
    }
    
    private val payloadCallback = object : PayloadCallback() {
        override fun onPayloadReceived(endpointId: String, payload: Payload) {
            if (payload.type == Payload.Type.BYTES) {
                val messageJson = String(payload.asBytes()!!)
                try {
                    val message = Json.decodeFromString<MeshMessage>(messageJson)
                    
                    // Deduplication
                    if (seenMessages.contains(message.messageId)) {
                        return
                    }
                    seenMessages.add(message.messageId)
                    
                    // Emit to observers
                    _receivedMessages.value = message
                    
                    // Propagate to other nodes (with hop limit)
                    if (message.hopCount < 5) {
                        propagateMessage(message, excludeEndpoint = endpointId)
                    }
                } catch (e: Exception) {
                    e.printStackTrace()
                }
            }
        }
        
        override fun onPayloadTransferUpdate(endpointId: String, update: PayloadTransferUpdate) {
            // Handle transfer progress if needed
        }
    }
    
    fun startAdvertising(districtCode: String, username: String) {
        val advertisingOptions = AdvertisingOptions.Builder()
            .setStrategy(strategy)
            .build()
        
        connectionsClient.startAdvertising(
            username,
            "district_$districtCode",
            connectionLifecycleCallback,
            advertisingOptions
        )
    }
    
    fun startDiscovery(districtCode: String) {
        val discoveryOptions = DiscoveryOptions.Builder()
            .setStrategy(strategy)
            .build()
        
        val endpointDiscoveryCallback = object : EndpointDiscoveryCallback() {
            override fun onEndpointFound(endpointId: String, info: DiscoveredEndpointInfo) {
                // Auto-connect to discovered endpoints
                connectionsClient.requestConnection(
                    "user",
                    endpointId,
                    connectionLifecycleCallback
                )
            }
            
            override fun onEndpointLost(endpointId: String) {
                // Handle endpoint lost
            }
        }
        
        connectionsClient.startDiscovery(
            "district_$districtCode",
            endpointDiscoveryCallback,
            discoveryOptions
        )
    }
    
    fun sendMessage(message: MeshMessage) {
        val json = Json.encodeToString(message)
        val payload = Payload.fromBytes(json.toByteArray())
        
        _connectedEndpoints.value.forEach { endpointId ->
            connectionsClient.sendPayload(endpointId, payload)
        }
    }
    
    private fun propagateMessage(message: MeshMessage, excludeEndpoint: String) {
        val updatedMessage = message.copy(hopCount = message.hopCount + 1)
        val json = Json.encodeToString(updatedMessage)
        val payload = Payload.fromBytes(json.toByteArray())
        
        _connectedEndpoints.value
            .filter { it != excludeEndpoint }
            .forEach { endpointId ->
                connectionsClient.sendPayload(endpointId, payload)
            }
    }
    
    private fun sendSyncRequest(endpointId: String) {
        val syncRequest = MeshMessage(
            type = "sync_request",
            districtId = 0, // Will be set by caller
            data = "{}",
            fromUsername = "system"
        )
        val payload = Payload.fromBytes(Json.encodeToString(syncRequest).toByteArray())
        connectionsClient.sendPayload(endpointId, payload)
    }
    
    fun stopAll() {
        connectionsClient.stopAdvertising()
        connectionsClient.stopDiscovery()
        connectionsClient.stopAllEndpoints()
    }
}
```

### 4. SyncWorker.kt

```kotlin
package com.yourapp.textsocial.service.sync

import android.content.Context
import androidx.work.*
import com.yourapp.textsocial.data.local.database.AppDatabase
import com.yourapp.textsocial.data.remote.RetrofitInstance
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit

class SyncWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {
    
    private val database = AppDatabase.getInstance(context)
    private val api = RetrofitInstance.api
    
    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        try {
            if (!isOnline()) {
                return@withContext Result.retry()
            }
            
            // Get unsynced posts
            val unsyncedPosts = database.postDao().getUnsyncedPosts()
            
            unsyncedPosts.forEach { post ->
                try {
                    val response = api.syncPost(
                        districtId = post.districtId,
                        posts = listOf(post.toDto())
                    )
                    
                    if (response.isSuccessful) {
                        response.body()?.let { syncResponse ->
                            // Update local post with server ID
                            database.postDao().updateSyncStatus(
                                uuid = post.uuid,
                                isSynced = true,
                                serverId = syncResponse.serverId
                            )
                        }
                    }
                } catch (e: Exception) {
                    // Log error but continue
                    e.printStackTrace()
                }
            }
            
            // Download updates from server
            syncFromServer()
            
            Result.success()
            
        } catch (e: Exception) {
            e.printStackTrace()
            Result.retry()
        }
    }
    
    private suspend fun syncFromServer() {
        val districts = database.districtDao().getAllDistricts()
        
        districts.forEach { district ->
            try {
                val lastSync = district.lastSyncTimestamp ?: 0
                val updates = api.getDistrictUpdates(district.id, lastSync)
                
                if (updates.isSuccessful) {
                    updates.body()?.let { data ->
                        // Insert new posts
                        data.posts.forEach { postDto ->
                            database.postDao().insertOrUpdate(postDto.toLocalPost())
                        }
                        
                        // Update sync timestamp
                        database.districtDao().updateLastSync(
                            district.id,
                            System.currentTimeMillis()
                        )
                    }
                }
            } catch (e: Exception) {
                e.printStackTrace()
            }
        }
    }
    
    private fun isOnline(): Boolean {
        // Check network connectivity
        return true // Implement actual check
    }
    
    companion object {
        const val WORK_NAME = "district_sync"
        
        fun schedulePeriodicSync(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()
            
            val syncRequest = PeriodicWorkRequestBuilder<SyncWorker>(
                15, TimeUnit.MINUTES
            )
                .setConstraints(constraints)
                .setBackoffCriteria(
                    BackoffPolicy.EXPONENTIAL,
                    10, TimeUnit.SECONDS
                )
                .build()
            
            WorkManager.getInstance(context)
                .enqueueUniquePeriodicWork(
                    WORK_NAME,
                    ExistingPeriodicWorkPolicy.KEEP,
                    syncRequest
                )
        }
    }
}
```

### 5. DistrictFeedActivity.kt

```kotlin
package com.yourapp.textsocial.presentation.ui.districts

import android.os.Bundle
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.yourapp.textsocial.databinding.ActivityDistrictFeedBinding
import com.yourapp.textsocial.presentation.adapter.PostAdapter
import kotlinx.coroutines.launch

class DistrictFeedActivity : AppCompatActivity() {
    
    private lateinit var binding: ActivityDistrictFeedBinding
    private val viewModel: DistrictViewModel by viewModels()
    private lateinit var postAdapter: PostAdapter
    
    private var districtId: Int = 0
    
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityDistrictFeedBinding.inflate(layoutInflater)
        setContentView(binding.root)
        
        districtId = intent.getIntExtra("district_id", 0)
        
        setupRecyclerView()
        setupObservers()
        setupMeshNetworking()
        
        viewModel.loadPosts(districtId)
    }
    
    private fun setupRecyclerView() {
        postAdapter = PostAdapter()
        binding.recyclerView.apply {
            layoutManager = LinearLayoutManager(this@DistrictFeedActivity)
            adapter = postAdapter
        }
    }
    
    private fun setupObservers() {
        // Observe posts
        viewModel.posts.observe(this) { posts ->
            postAdapter.submitList(posts)
        }
        
        // Observe connection status
        viewModel.connectionStatus.observe(this) { status ->
            binding.statusIndicator.text = when (status) {
                ConnectionStatus.OFFLINE_MESH -> "📡 Offline (Mesh: ${viewModel.connectedNodes.value} nodes)"
                ConnectionStatus.ONLINE -> "🌐 Online"
                ConnectionStatus.SYNCING -> "⚡ Syncing..."
            }
        }
        
        // Observe mesh messages
        lifecycleScope.launch {
            viewModel.meshMessages.collect { message ->
                message?.let {
                    viewModel.handleMeshMessage(it)
                }
            }
        }
    }
    
    private fun setupMeshNetworking() {
        viewModel.startMeshNetworking(districtId)
    }
    
    override fun onDestroy() {
        super.onDestroy()
        viewModel.stopMeshNetworking()
    }
}
```

---

## Permissions Required (AndroidManifest.xml)

```xml
<manifest>
    <!-- Nearby Connections -->
    <uses-permission android:name="android.permission.BLUETOOTH" />
    <uses-permission android:name="android.permission.BLUETOOTH_ADMIN" />
    <uses-permission android:name="android.permission.BLUETOOTH_ADVERTISE" />
    <uses-permission android:name="android.permission.BLUETOOTH_CONNECT" />
    <uses-permission android:name="android.permission.BLUETOOTH_SCAN" />
    
    <!-- WiFi Direct -->
    <uses-permission android:name="android.permission.ACCESS_WIFI_STATE" />
    <uses-permission android:name="android.permission.CHANGE_WIFI_STATE" />
    <uses-permission android:name="android.permission.NEARBY_WIFI_DEVICES" />
    
    <!-- Location (required for Bluetooth scanning) -->
    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
    <uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
    
    <!-- Internet -->
    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
</manifest>
```

---

## Next Steps

1. **Clone or create new Android project**
2. **Add dependencies** from above
3. **Implement database layer** (entities, DAOs)
4. **Implement mesh networking** (MeshNetworkManager)
5. **Implement sync service** (SyncWorker)
6. **Build UI** (Activities, Fragments, Adapters)
7. **Test on multiple devices** (minimum 3 for mesh testing)
8. **Optimize battery usage** (background restrictions, wake locks)
9. **Add conflict resolution** (for simultaneous offline edits)
10. **Implement media sync** (images/videos over mesh - chunking required)

---

## Testing Checklist

- [ ] Bluetooth mesh connectivity (3+ devices)
- [ ] WiFi Direct fallback
- [ ] Offline post creation
- [ ] Message propagation through mesh
- [ ] Sync to server when online
- [ ] Conflict resolution
- [ ] Battery optimization
- [ ] Background service restrictions
- [ ] Permission handling
- [ ] Network transition (offline → online → offline)
