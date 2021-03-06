# TODO: Copyrights

IF (NOT EXISTS "${CMAKE_SOURCE_DIR}/rocksdb/Makefile")
  MESSAGE(SEND_ERROR "Missing Makefile in rocksdb directory. Try \"git submodule update\".")
ENDIF()

CHECK_FUNCTION_EXISTS(sched_getcpu  HAVE_SCHED_GETCPU)
IF(HAVE_SCHED_GETCPU)
  ADD_DEFINITIONS(-DHAVE_SCHED_GETCPU=1 -DROCKSDB_SCHED_GETCPU_PRESENT)
ENDIF()

# get a list of rocksdb library source files
# run with env -i to avoid passing variables
EXECUTE_PROCESS(
  COMMAND env -i ${CMAKE_SOURCE_DIR}/storage/rocksdb/get_rocksdb_files.sh
  OUTPUT_VARIABLE SCRIPT_OUTPUT
  WORKING_DIRECTORY ${CMAKE_SOURCE_DIR}
)
# split the list into lines
STRING(REGEX MATCHALL "[^\n]+" ROCKSDB_LIB_SOURCES ${SCRIPT_OUTPUT})

INCLUDE_DIRECTORIES(
  /usr/local/cuda/include
  /usr/local/include/donard
  ${CMAKE_SOURCE_DIR}/rocksdb
  ${CMAKE_SOURCE_DIR}/rocksdb/include
  ${CMAKE_SOURCE_DIR}/rocksdb/accelerator
  ${CMAKE_SOURCE_DIR}/rocksdb/accelerator/avx
  ${CMALE_SOURCE_DIR}/rocksdb/accelerator/cuda
  ${CMAKE_SOURCE_DIR}/rocksdb/third-party/gtest-1.7.0/fused-src
)

# This is a strong requirement coming from RocksDB. No conditional checks here.
ADD_DEFINITIONS(-DROCKSDB_PLATFORM_POSIX -DROCKSDB_LIB_IO_POSIX)

IF(UNIX)
  IF(CMAKE_SYSTEM_NAME STREQUAL "Linux")
    ADD_DEFINITIONS(-DOS_LINUX)

    CHECK_INCLUDE_FILES(linux/falloc.h HAVE_LINUX_FALLOC_H)
    CHECK_FUNCTION_EXISTS(fallocate HAVE_FALLOCATE)

    IF(HAVE_FALLOCATE AND HAVE_LINUX_FALLOC_H)
      ADD_DEFINITIONS(-DROCKSDB_FALLOCATE_PRESENT)
    ENDIF()
  ENDIF()
ENDIF()

CHECK_CXX_SOURCE_COMPILES("
#if defined(_MSC_VER) && !defined(__thread)
#define __thread __declspec(thread)
#endif
int main() {
  static __thread int tls;
}
" HAVE_THREAD_LOCAL)
if(HAVE_THREAD_LOCAL)
  ADD_DEFINITIONS(-DROCKSDB_SUPPORT_THREAD_LOCAL)
endif()

SET(ROCKSDB_SOURCES
  ha_rocksdb.cc ha_rocksdb.h ha_rocksdb_proto.h
  logger.h
  rdb_comparator.h
  rdb_datadic.cc rdb_datadic.h
  rdb_cf_options.cc rdb_cf_options.h
  rdb_cf_manager.cc rdb_cf_manager.h
  properties_collector.cc properties_collector.h
  event_listener.cc event_listener.h
  rdb_i_s.cc rdb_i_s.h
  rdb_index_merge.cc rdb_index_merge.h
  rdb_io_watchdog.cc rdb_io_watchdog.h
  rdb_perf_context.cc rdb_perf_context.h
  rdb_mutex_wrapper.cc rdb_mutex_wrapper.h
  rdb_psi.h rdb_psi.cc
  rdb_sst_info.cc rdb_sst_info.h
  rdb_utils.cc rdb_utils.h rdb_buff.h
  rdb_threads.cc rdb_threads.h
  ${ROCKSDB_LIB_SOURCES}
)

IF(WITH_FB_TSAN)
  SET(PIC_EXT "_pic")
ELSE()
  SET(PIC_EXT "")
ENDIF()

SET(rocksdb_static_libs )

IF (WITH_JEMALLOC)
  FIND_LIBRARY(JEMALLOC_LIBRARY
    NAMES libjemalloc${PIC_EXT}.a jemalloc
    HINTS ${WITH_JEMALLOC}/lib)
  SET(rocksdb_static_libs ${rocksdb_static_libs}
    ${JEMALLOC_LIBRARY})
  ADD_DEFINITIONS(-DROCKSDB_JEMALLOC)
  ADD_DEFINITIONS(-DROCKSDB_MALLOC_USABLE_SIZE)
ENDIF()

IF (WITH_UNWIND)
  FIND_LIBRARY(UNWIND_LIBRARY
    NAMES libunwind${PIC_EXT}.a unwind
    HINTS ${WITH_UNWIND}/lib)
  SET(rocksdb_static_libs ${rocksdb_static_libs}
    ${UNWIND_LIBRARY})
ENDIF()

IF (WITH_SNAPPY)
  FIND_LIBRARY(SNAPPY_LIBRARY
    NAMES libsnappy${PIC_EXT}.a snappy
    HINTS ${WITH_SNAPPY}/lib)
  SET(rocksdb_static_libs ${rocksdb_static_libs}
    ${SNAPPY_LIBRARY})
  ADD_DEFINITIONS(-DSNAPPY)
ENDIF()

IF (WITH_LZ4)
  FIND_LIBRARY(LZ4_LIBRARY
    NAMES liblz4${PIC_EXT}.a lz4
    HINTS ${WITH_LZ4}/lib)
  SET(rocksdb_static_libs ${rocksdb_static_libs}
    ${LZ4_LIBRARY})
  ADD_DEFINITIONS(-DLZ4)
ENDIF()

IF (WITH_BZ2)
  FIND_LIBRARY(BZ2_LIBRARY
    NAMES libbz2${PIC_EXT}.a bz2
    HINTS ${WITH_BZ2}/lib)
  SET(rocksdb_static_libs ${rocksdb_static_libs}
    ${BZ2_LIBRARY})
  ADD_DEFINITIONS(-DBZIP2)
ENDIF()

IF (WITH_ZSTD)
  SET(rocksdb_static_libs ${rocksdb_static_libs} ${ZSTD_LIBRARY})
  ADD_DEFINITIONS(-DZSTD)
ENDIF()

IF (WITH_TBB)
  FIND_LIBRARY(TBB_LIBRARY
    NAMES libtbb${PIC_EXT}.a tbb
    HINTS ${WITH_TBB}/lib)
  SET(rocksdb_static_libs ${rocksdb_static_libs}
    ${TBB_LIBRARY})
  ADD_DEFINITIONS(-DTBB)
ENDIF()

CHECK_INCLUDE_FILES(zlib.h HAVE_ZLIB_H)

IF (HAVE_ZLIB_H)
  ADD_DEFINITIONS(-DZLIB)
  SET(rocksdb_static_libs ${rocksdb_static_libs} ${ZLIB_LIBRARY})
ENDIF()

# Rocksdb-GPU
IF (WITH_RUDA)
  MESSAGE(STATUS "MAKE BEFORE")
  EXECUTE_PROCESS(
    COMMAND make accelerator/cuda/libruda.a -j8
    WORKING_DIRECTORY ${CMAKE_SOURCE_DIR}/rocksdb
  )
  MESSAGE(STATUS "MAKE AFTER")
  FIND_LIBRARY(RUDA_LIBRARY
    NAMES ruda
    HINTS ${CMAKE_SOURCE_DIR}/rocksdb/accelerator/cuda)
  SET(rocksdb_static_libs
    ${rocksdb_static_libs}
    ${RUDA_LIBRARY} "-L/usr/local/cuda/lib64" "-lcudart" "-lcudadevrt" "-ldonard")
ENDIF()

SET(rocksdb_static_libs ${rocksdb_static_libs} "-lrt" "-ldl" "-lpthread")

MYSQL_ADD_PLUGIN(rocksdb_se ${ROCKSDB_SOURCES} STORAGE_ENGINE DEFAULT STATIC_ONLY
  LINK_LIBRARIES ${rocksdb_static_libs}
)

IF(WITH_EMBEDDED_SERVER)
  ADD_SUBDIRECTORY(unittest)
ENDIF()

IF (WITH_ROCKSDB_SE_STORAGE_ENGINE)
  # TODO: read this file list from src.mk:TOOL_SOURCES
  SET(ROCKSDB_TOOL_SOURCES
    ${CMAKE_SOURCE_DIR}/rocksdb/tools/ldb_tool.cc
    ${CMAKE_SOURCE_DIR}/rocksdb/tools/ldb_cmd.cc
    ${CMAKE_SOURCE_DIR}/rocksdb/tools/sst_dump_tool.cc
  )
  MYSQL_ADD_EXECUTABLE(sst_dump ${CMAKE_SOURCE_DIR}/rocksdb/tools/sst_dump.cc ${ROCKSDB_TOOL_SOURCES})
  TARGET_LINK_LIBRARIES(sst_dump rocksdb_se)

  MYSQL_ADD_EXECUTABLE(ldb ${CMAKE_SOURCE_DIR}/rocksdb/tools/ldb.cc ${ROCKSDB_TOOL_SOURCES})
  TARGET_LINK_LIBRARIES(ldb rocksdb_se)

  MYSQL_ADD_EXECUTABLE(mysql_ldb ${CMAKE_SOURCE_DIR}/storage/rocksdb/tools/mysql_ldb.cc ${ROCKSDB_TOOL_SOURCES})
  TARGET_LINK_LIBRARIES(mysql_ldb rocksdb_se)
ENDIF()
